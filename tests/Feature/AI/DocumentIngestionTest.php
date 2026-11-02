<?php

namespace Tests\Feature\AI;

use App\Services\AI\DocumentIngestion\ChunkDraft;
use App\Services\AI\DocumentIngestion\DocumentMetadata;
use App\Services\AI\DocumentIngestion\PdfIngestionConfig;
use App\Services\AI\DocumentIngestion\PdfIngestionException;
use App\Services\AI\DocumentIngestion\PdfIngestionService;
use App\Services\AI\DocumentIngestion\PdfTextExtractor;
use App\Services\AI\DocumentIngestion\TextChunker;
use App\Services\AI\DocumentIngestion\TextNormalizer;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentIngestionTest extends TestCase
{
    public function test_normalization_is_idempotent_and_preserves_technical_text(): void
    {
        $normalizer = new TextNormalizer;
        $source = "\u{FEFF}  FNC-01\r\n\tPM-FNC-01-MAN-001\r\n\r\n\r\nLG-2\u{00A0} 2,5–3,5 bar\r1.000 jam\n15 gram per bearing\nOK\nMONITOR\nNG\n";

        $normalized = $normalizer->normalize($source);

        $this->assertSame("FNC-01\nPM-FNC-01-MAN-001\n\nLG-2 2,5–3,5 bar\n1.000 jam\n15 gram per bearing\nOK\nMONITOR\nNG", $normalized);
        $this->assertSame($normalized, $normalizer->normalize($normalized));
    }

    public function test_normalization_rejects_invalid_utf8(): void
    {
        $this->expectException(PdfIngestionException::class);
        (new TextNormalizer)->normalize("valid\xFFtext");
    }

    public function test_normalization_canonicalizes_unicode_and_list_indentation(): void
    {
        $normalized = (new TextNormalizer)->normalize("e\u{0301}\n    - Item\n      continuation\n  1) Next");
        $this->assertSame("é\n- Item\n  continuation\n1) Next", $normalized);
    }

    public function test_list_continuation_normalization_is_idempotent(): void
    {
        $normalizer = new TextNormalizer;
        $normalized = $normalizer->normalize("   - item one\n     continued text\n");

        $this->assertSame("- item one\n  continued text", $normalized);
        $this->assertSame($normalized, $normalizer->normalize($normalized));
    }

    public function test_document_metadata_rejects_missing_identity_as_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentMetadata('', '1.0');
    }

    public function test_extractor_compatibility_policy_changes_pipeline_fingerprint(): void
    {
        $first = PdfIngestionConfig::fromArray(['extractor_compatibility' => 'pdftotext-layout-compat-a']);
        $second = PdfIngestionConfig::fromArray(['extractor_compatibility' => 'pdftotext-layout-compat-b']);

        $this->assertNotSame($first->fingerprint(), $second->fingerprint());
    }

    public function test_numbered_headings_update_section_provenance_but_procedure_numbers_do_not(): void
    {
        $drafts = $this->chunker()->chunk([
            1 => "Before heading\n\n1. Matikan mesin\n\n8. COOLING WATER SYSTEM\n\nCooling data\n\n8.2 Standar Tekanan Cooling Water\n\n2,5–3,5 bar\n\n2.2.1 Induction Coil\n\nCoil data",
        ], $this->config());

        $this->assertSame([null, [8], [8, 2], [2, 2, 1]], array_map(fn (ChunkDraft $draft) => $draft->sectionPath, $drafts));
        $this->assertSame([null, 1, 1, 1], array_map(fn (ChunkDraft $draft) => $draft->headingOriginPage, $drafts));
        $this->assertStringContainsString('1. Matikan mesin', $drafts[0]->text);
    }

    public function test_section_path_carries_across_pages_and_same_section_chunk_has_truthful_range(): void
    {
        $drafts = $this->chunker()->chunk([
            1 => "8. COOLING WATER SYSTEM\n\nOn page one",
            2 => 'On page two',
        ], $this->config());

        $this->assertCount(1, $drafts);
        $this->assertSame([8], $drafts[0]->sectionPath);
        $this->assertSame([1, 2], [$drafts[0]->pageStart, $drafts[0]->pageEnd]);
        $this->assertSame("8. COOLING WATER SYSTEM\n\nOn page one\nOn page two", $drafts[0]->text);
    }

    public function test_blank_interior_page_is_retained_with_truthful_numbering(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'valid.pdf';
        file_put_contents($path, "%PDF-1.4\nfixture\n");
        $extractor = new PdfTextExtractor($this->config(), static fn (): array => [
            'stdout' => "First page\f\fThird page\f",
            'stderr' => '',
            'exit_code' => 0,
        ]);

        try {
            $extracted = $extractor->extract($path);

            $this->assertCount(3, $extracted->pages);
            $this->assertSame([1, 2, 3], array_column($extracted->pages, 'pageNumber'));
            $this->assertSame('', $extracted->pages[1]->text);
            $this->assertSame('Third page', $extracted->pages[2]->text);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_source_hash_reflects_the_bytes_actually_extracted(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'source.pdf';
        $bytes = "%PDF-1.4\nsnapshot bytes\n";
        file_put_contents($path, $bytes);

        try {
            $extracted = (new PdfTextExtractor($this->config(), static fn (): array => [
                'stdout' => "Extracted\f",
                'stderr' => '',
                'exit_code' => 0,
            ]))->extract($path);

            $this->assertSame(hash('sha256', $bytes), $extracted->sourceSha256);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_extractor_rejects_urls_and_missing_files(): void
    {
        $extractor = new PdfTextExtractor($this->config(), static fn (): array => [
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 0,
        ]);

        foreach (['http://example.test/a.pdf', 'php://filter/read=convert.base64-encode/resource=config/ai.php', 'C:/definitely/missing.pdf'] as $source) {
            try {
                $extractor->extract($source);
                $this->fail("Expected intake rejection for {$source}.");
            } catch (PdfIngestionException $exception) {
                $this->assertStringNotContainsString($source, $exception->getMessage());
            }
        }
    }

    public function test_extractor_uses_and_cleans_exact_hashed_source_snapshot(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'source.pdf';
        $bytes = "%PDF-1.4\nexact snapshot\n";
        file_put_contents($path, $bytes);
        $capturedPath = null;
        $capturedBytes = null;

        try {
            $extractor = new PdfTextExtractor($this->config(), static function (array $command) use (&$capturedPath, &$capturedBytes): array {
                $capturedPath = $command[8];
                if (! is_string($capturedPath) || ! is_file($capturedPath)) {
                    throw new \RuntimeException('Extractor snapshot was not created.');
                }
                $capturedBytes = file_get_contents($capturedPath);

                return ['stdout' => "Snapshot content\f", 'stderr' => '', 'exit_code' => 0];
            });
            $extracted = $extractor->extract($path);

            $this->assertNotSame($path, $capturedPath);
            $this->assertSame($bytes, $capturedBytes);
            $this->assertSame(hash('sha256', $bytes), $extracted->sourceSha256);
            $this->assertFileDoesNotExist($capturedPath);
        } finally {
            @unlink($capturedPath ?? '');
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_chunk_source_spans_reconstruct_exact_normalized_source_text(): void
    {
        $source = "8. COOLING WATER SYSTEM\n\nFirst paragraph.\ncontinues.\n\n- List item\n\nLast line.";
        $drafts = $this->chunker()->chunk([1 => $source], $this->config());

        foreach ($drafts as $draft) {
            $reconstructed = '';
            foreach ($draft->sourceSpans as $span) {
                $reconstructed .= substr($source, $span->start, $span->end - $span->start).$span->separatorAfter;
            }
            $this->assertSame($draft->text, $reconstructed);
        }

    }

    public function test_chunking_enforces_grapheme_and_utf8_byte_limits(): void
    {
        $config = $this->config(targetChunkGraphemes: 4, hardChunkGraphemes: 8, hardChunkBytes: 10);
        $drafts = $this->chunker()->chunk([1 => 'éééééééééééé'], $config);

        $this->assertGreaterThan(1, count($drafts));
        foreach ($drafts as $draft) {
            $this->assertLessThanOrEqual(8, preg_match_all('/\X/u', $draft->text));
            $this->assertLessThanOrEqual(10, strlen($draft->text));
        }
        $this->assertSame('éééééééééééé', implode('', array_map(fn (ChunkDraft $draft) => $draft->text, $drafts)));
    }

    public function test_ingestion_stable_identity_ignores_filename_and_changes_with_pipeline(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $firstPath = $directory.DIRECTORY_SEPARATOR.'first.pdf';
        $secondPath = $directory.DIRECTORY_SEPARATOR.'renamed.pdf';
        file_put_contents($firstPath, "%PDF-1.4\nsynthetic bytes\n");
        copy($firstPath, $secondPath);

        $runner = static fn (): array => ['stdout' => "8. COOLING WATER SYSTEM\n\nLG-2 2,5–3,5 bar\f", 'stderr' => '', 'exit_code' => 0];
        try {
            $first = $this->service($runner, $this->config())->ingest($firstPath, new DocumentMetadata('PM-FNC-01-MAN-001', '1.0', 'FNC-01'));
            $renamed = $this->service($runner, $this->config())->ingest($secondPath, new DocumentMetadata('PM-FNC-01-MAN-001', '1.0', 'FNC-01'));
            $changed = $this->service($runner, $this->config(pipelineVersion: 'ai-3b1.2'))->ingest($firstPath, new DocumentMetadata('PM-FNC-01-MAN-001', '1.0', 'FNC-01'));

            $this->assertSame(array_column($first, 'stableChunkIdentity'), array_column($renamed, 'stableChunkIdentity'));
            $this->assertNotSame($first[0]->stableChunkIdentity, $changed[0]->stableChunkIdentity);
            $this->assertSame('first.pdf', $first[0]->sourceFilename);
            $this->assertSame('renamed.pdf', $renamed[0]->sourceFilename);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
            @rmdir($directory);
        }
    }

    public function test_extractor_rejects_non_pdf_and_caps_process_output(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $invalid = $directory.DIRECTORY_SEPARATOR.'bad.pdf';
        $valid = $directory.DIRECTORY_SEPARATOR.'valid.pdf';
        file_put_contents($invalid, 'not pdf');
        file_put_contents($valid, "%PDF-1.4\nfixture\n");

        $this->expectException(PdfIngestionException::class);
        try {
            (new PdfTextExtractor($this->config(), static fn (): array => ['stdout' => 'ignored', 'stderr' => '', 'exit_code' => 0]))->extract($invalid);
        } finally {
            @unlink($invalid);
            @unlink($valid);
            @rmdir($directory);
        }
    }

    public function test_source_content_changes_stable_chunk_identity(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'source.pdf';
        file_put_contents($path, "%PDF-1.4\nfirst bytes\n");
        $config = $this->config();
        $runner = static fn (): array => ['stdout' => 'Same extracted text', 'stderr' => '', 'exit_code' => 0];

        try {
            $first = $this->service($runner, $config)->ingest($path, new DocumentMetadata('DOC-1', '1.0'));
            file_put_contents($path, "%PDF-1.4\nchanged bytes\n");
            $second = $this->service($runner, $config)->ingest($path, new DocumentMetadata('DOC-1', '1.0'));

            $this->assertNotSame($first[0]->stableChunkIdentity, $second[0]->stableChunkIdentity);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_process_failure_and_excessive_runner_output_fail_closed(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'valid.pdf';
        file_put_contents($path, "%PDF-1.4\nfixture\n");
        $config = PdfIngestionConfig::fromArray(['binary' => 'pdftotext', 'max_total_text_bytes' => 16]);

        try {
            $failure = new PdfTextExtractor($config, static fn (): array => ['stdout' => '', 'stderr' => '', 'exit_code' => 7]);
            try {
                $failure->extract($path);
                $this->fail('Expected nonzero-process-style failure.');
            } catch (PdfIngestionException $exception) {
                $this->assertSame('Ekstraksi PDF gagal dijalankan oleh extractor yang dikonfigurasi.', $exception->getMessage());
            }

            $oversized = new PdfTextExtractor($config, static fn (): array => ['stdout' => str_repeat('x', 17), 'stderr' => '', 'exit_code' => 0]);
            $this->expectException(PdfIngestionException::class);
            $oversized->extract($path);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_exceeded_extraction_timeout_fails_closed(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'valid.pdf';
        file_put_contents($path, "%PDF-1.4\nfixture\n");
        $process = new Process(['pdftotext']);
        $config = PdfIngestionConfig::fromArray(['binary' => 'pdftotext']);
        $timeout = new PdfTextExtractor($config, static fn (): never => throw new ProcessTimedOutException(
            $process,
            ProcessTimedOutException::TYPE_GENERAL,
        ));

        try {
            $timeout->extract($path);
            $this->fail('Expected extraction timeout failure.');
        } catch (PdfIngestionException $exception) {
            $this->assertSame('Ekstraksi PDF melewati batas waktu yang diizinkan.', $exception->getMessage());
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_extracted_page_count_is_bounded_and_blank_physical_pages_are_retained(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prime-ai-3b1-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'valid.pdf';
        file_put_contents($path, "%PDF-1.4\nfixture\n");

        try {
            $config = PdfIngestionConfig::fromArray(['binary' => 'pdftotext', 'max_pages' => 2]);
            $extractor = new PdfTextExtractor($config, static fn (): array => ['stdout' => "Page one\n\f\fPage three", 'stderr' => '', 'exit_code' => 0]);
            $this->expectException(PdfIngestionException::class);
            $extractor->extract($path);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    private function chunker(): TextChunker
    {
        return new TextChunker;
    }

    private function config(
        int $targetChunkGraphemes = 1800,
        int $hardChunkGraphemes = 3000,
        int $hardChunkBytes = 12000,
        string $pipelineVersion = 'ai-3b1.1',
    ): PdfIngestionConfig {
        return PdfIngestionConfig::fromArray([
            'binary' => 'pdftotext',
            'target_chunk_graphemes' => $targetChunkGraphemes,
            'hard_chunk_graphemes' => $hardChunkGraphemes,
            'hard_chunk_bytes' => $hardChunkBytes,
            'pipeline_version' => $pipelineVersion,
        ]);
    }

    private function service(callable $runner, PdfIngestionConfig $config): PdfIngestionService
    {
        return new PdfIngestionService(
            new PdfTextExtractor($config, $runner),
            new TextNormalizer,
            new TextChunker,
            $config,
        );
    }
}
