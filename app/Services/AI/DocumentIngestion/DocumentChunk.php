<?php

namespace App\Services\AI\DocumentIngestion;

final readonly class DocumentChunk
{
    /** @param list<int> $sectionPath @param list<SourceSpan> $sourceSpans */
    public function __construct(
        public string $documentIdentity,
        public string $documentCode,
        public string $documentVersion,
        public string $sourceFilename,
        public string $sourceSha256,
        public ?string $machineCode,
        public string $pipelineFingerprint,
        public int $pageStart,
        public int $pageEnd,
        public ?array $sectionPath,
        public ?string $sectionIdentifier,
        public ?string $sectionTitle,
        public ?int $headingOriginPage,
        public int $chunkIndex,
        public string $text,
        public array $sourceSpans,
        public string $stableChunkIdentity,
    ) {}
}
