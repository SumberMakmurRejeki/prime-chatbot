<?php

namespace App\Services\AI\DocumentIngestion;

/**
 * Potongan deterministik sebelum identitas stabil dan metadata dokumen dilekatkan.
 *
 * Dipakai internal oleh TextChunker → PdfIngestionService.
 */
final readonly class ChunkDraft
{
    /**
     * @param  list<SourceSpan>  $sourceSpans
     * @param  list<int>|null  $sectionPath
     */
    public function __construct(
        public string $text,
        public array $sourceSpans,
        public int $pageStart,
        public int $pageEnd,
        public ?array $sectionPath,
        public ?string $sectionTitle,
        public ?int $headingOriginPage,
    ) {}
}
