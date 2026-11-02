<?php

namespace App\Services\AI\DocumentIngestion;

final readonly class PdfIngestionService
{
    public function __construct(
        private PdfTextExtractor $extractor,
        private TextNormalizer $normalizer,
        private TextChunker $chunker,
        private PdfIngestionConfig $config,
    ) {}

    /**
     * @return list<DocumentChunk>
     */
    public function ingest(string $sourcePath, DocumentMetadata $metadata): array
    {
        $extracted = $this->extractor->extract($sourcePath);
        $normalizedPages = [];

        foreach ($extracted->pages as $page) {
            $normalizedPage = $this->normalizer->normalize($page->text);
            if (strlen($normalizedPage) > $this->config->maxPageTextBytes) {
                throw PdfIngestionException::pageTextTooLarge();
            }
            $normalizedPages[$page->pageNumber] = $normalizedPage;
        }

        $sourceSha256 = $extracted->sourceSha256;
        $filename = basename($sourcePath);
        $fingerprint = $this->config->fingerprint();
        $drafts = $this->chunker->chunk($normalizedPages, $this->config);
        $chunks = [];

        foreach ($drafts as $index => $draft) {
            $identityPayload = [
                'identity_schema' => PdfIngestionConfig::IDENTITY_SCHEMA_VERSION,
                'source_pdf_sha256' => $sourceSha256,
                'pipeline_fingerprint' => $fingerprint,
                'document_code' => $metadata->documentCode,
                'document_version' => $metadata->documentVersion,
                'chunk_index' => $index,
                'section_path' => $draft->sectionPath,
                'source_spans' => array_map(
                    fn (SourceSpan $span): array => ['page' => $span->page, 'start' => $span->start, 'end' => $span->end],
                    $draft->sourceSpans,
                ),
                'text' => $draft->text,
            ];

            $chunks[] = new DocumentChunk(
                documentIdentity: $metadata->documentCode.'@'.$metadata->documentVersion,
                documentCode: $metadata->documentCode,
                documentVersion: $metadata->documentVersion,
                sourceFilename: $filename,
                sourceSha256: $sourceSha256,
                machineCode: $metadata->machineCode,
                pipelineFingerprint: $fingerprint,
                pageStart: $draft->pageStart,
                pageEnd: $draft->pageEnd,
                sectionPath: $draft->sectionPath,
                sectionIdentifier: $draft->sectionPath === null ? null : implode('.', $draft->sectionPath),
                sectionTitle: $draft->sectionTitle,
                headingOriginPage: $draft->headingOriginPage,
                chunkIndex: $index,
                text: $draft->text,
                sourceSpans: $draft->sourceSpans,
                stableChunkIdentity: hash('sha256', PdfIngestionConfig::encode($identityPayload)),
            );
        }

        return $chunks;
    }
}
