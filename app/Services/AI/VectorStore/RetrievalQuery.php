<?php

namespace App\Services\AI\VectorStore;

use InvalidArgumentException;

final readonly class RetrievalQuery
{
    /** @param list<int|float> $vector */
    public function __construct(
        public array $vector,
        public int $limit,
        public ?string $documentId = null,
        public ?string $documentVersion = null,
        public bool $onlyActive = true,
    ) {
        if ($documentId !== null && trim($documentId) === '') {
            throw new InvalidArgumentException('Document identity cannot be empty.');
        }

        if ($documentVersion !== null && trim($documentVersion) === '') {
            throw new InvalidArgumentException('Document version cannot be empty.');
        }

        if ($documentVersion !== null && $documentId === null) {
            throw new InvalidArgumentException('Document version requires document identity.');
        }
    }
}
