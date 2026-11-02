<?php

namespace App\Services\AI\VectorStore;

final readonly class VectorSearchResult
{
    /** @param array<string, mixed> $provenance */
    public function __construct(
        public int|string $pointId,
        public float $score,
        public string $documentId,
        public string $documentVersion,
        public string $vectorSpace,
        public string $locator,
        public ?int $pageNumber,
        public string $text,
        public array $provenance = [],
    ) {}
}
