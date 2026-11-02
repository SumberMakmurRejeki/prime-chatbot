<?php

namespace App\Services\AI\DocumentIngestion;

use JsonException;

/**
 * Konfigurasi pipeline ingestion PDF (AI-3B1).
 *
 * Semua batas resource dan versi algoritma ada di sini supaya perilaku
 * identity-affecting selalu terlihat dan bisa diuji. Perubahan nilai apa pun
 * yang memengaruhi identitas akan mengubah pipeline fingerprint.
 */
final readonly class PdfIngestionConfig
{
    public const IDENTITY_SCHEMA_VERSION = 'prime-ai-3b1-chunk-identity/1';

    public const OVERLAP_GRAPHEMES = 0;

    /** @var array<string, int|string> */
    private const DEFAULTS = [
        'binary' => 'pdftotext',
        'timeout_seconds' => 60,
        'max_source_bytes' => 20 * 1024 * 1024,
        'max_pages' => 200,
        'max_total_text_bytes' => 8 * 1024 * 1024,
        'max_page_text_bytes' => 256 * 1024,
        'max_stderr_bytes' => 64 * 1024,
        'target_chunk_graphemes' => 1800,
        'hard_chunk_graphemes' => 3000,
        'hard_chunk_bytes' => 12000,
        'pipeline_version' => 'ai-3b1.1',
        'extractor_family' => 'pdftotext',
        'extractor_contract' => 'xpdf-layout-utf8-eol-unix-private-cfg',
        // Kebijakan kompatibilitas extractor yang DITERIMA. Mengganti build/binary
        // pdftotext dengan perilaku berbeda wajib menaikkan token ini karena
        // token ini bagian dari pipeline fingerprint.
        'extractor_compatibility' => 'pdftotext-layout-utf8-compat-1',
    ];

    public function __construct(
        public string $binary,
        public int $timeoutSeconds,
        public int $maxSourceBytes,
        public int $maxPages,
        public int $maxTotalTextBytes,
        public int $maxPageTextBytes,
        public int $maxStderrBytes,
        public int $targetChunkGraphemes,
        public int $hardChunkGraphemes,
        public int $hardChunkBytes,
        public string $pipelineVersion,
        public string $extractorFamily,
        public string $extractorContract,
        public string $extractorCompatibility,
    ) {
        if (trim($this->binary) === '' || trim($this->pipelineVersion) === ''
            || trim($this->extractorFamily) === '' || trim($this->extractorContract) === ''
            || trim($this->extractorCompatibility) === '') {
            throw PdfIngestionException::configurationRejected();
        }

        if ($this->timeoutSeconds < 1 || $this->maxSourceBytes < 1 || $this->maxPages < 1
            || $this->maxTotalTextBytes < 1 || $this->maxPageTextBytes < 1 || $this->maxStderrBytes < 1
            || $this->hardChunkBytes < 1) {
            throw PdfIngestionException::configurationRejected();
        }

        if ($this->targetChunkGraphemes < 1
            || $this->hardChunkGraphemes < $this->targetChunkGraphemes) {
            throw PdfIngestionException::configurationRejected();
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $merged = $values + self::DEFAULTS;

        return new self(
            binary: self::text($merged, 'binary'),
            timeoutSeconds: self::positiveInt($merged, 'timeout_seconds'),
            maxSourceBytes: self::positiveInt($merged, 'max_source_bytes'),
            maxPages: self::positiveInt($merged, 'max_pages'),
            maxTotalTextBytes: self::positiveInt($merged, 'max_total_text_bytes'),
            maxPageTextBytes: self::positiveInt($merged, 'max_page_text_bytes'),
            maxStderrBytes: self::positiveInt($merged, 'max_stderr_bytes'),
            targetChunkGraphemes: self::positiveInt($merged, 'target_chunk_graphemes'),
            hardChunkGraphemes: self::positiveInt($merged, 'hard_chunk_graphemes'),
            hardChunkBytes: self::positiveInt($merged, 'hard_chunk_bytes'),
            pipelineVersion: self::text($merged, 'pipeline_version'),
            extractorFamily: self::text($merged, 'extractor_family'),
            extractorContract: self::text($merged, 'extractor_contract'),
            extractorCompatibility: self::text($merged, 'extractor_compatibility'),
        );
    }

    /**
     * Fingerprint pipeline deterministik dari perilaku yang memengaruhi identitas.
     *
     * Path executable, direktori sementara, dan timestamp sengaja tidak diikutkan
     * agar fingerprint sama pada mesin berbeda dengan kontrak yang sama.
     */
    public function fingerprint(): string
    {
        $payload = [
            'extractor_family' => $this->extractorFamily,
            'extractor_contract' => $this->extractorContract,
            'extractor_compatibility' => $this->extractorCompatibility,
            'normalization_version' => TextNormalizer::VERSION,
            'section_detector_version' => TextChunker::SECTION_DETECTOR_VERSION,
            'chunk_algorithm_version' => TextChunker::ALGORITHM_VERSION,
            'unicode_form' => 'nfc',
            'target_chunk_graphemes' => $this->targetChunkGraphemes,
            'hard_chunk_graphemes' => $this->hardChunkGraphemes,
            'hard_chunk_bytes' => $this->hardChunkBytes,
            'overlap_graphemes' => self::OVERLAP_GRAPHEMES,
            'pipeline_version' => $this->pipelineVersion,
        ];

        return hash('sha256', self::encode($payload));
    }

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw PdfIngestionException::configurationRejected();
        }
    }

    /** @param array<string, mixed> $values */
    private static function positiveInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            throw PdfIngestionException::configurationRejected();
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $values */
    private static function text(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw PdfIngestionException::configurationRejected();
        }

        return trim($value);
    }
}
