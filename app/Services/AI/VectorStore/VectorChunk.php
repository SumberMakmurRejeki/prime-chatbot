<?php

namespace App\Services\AI\VectorStore;

use InvalidArgumentException;

final readonly class VectorChunk
{
    /**
     * @param  list<int|float>  $vector
     * @param  array<string, scalar|list<scalar>>  $metadata
     */
    public function __construct(
        public int|string $pointId,
        public array $vector,
        public string $text,
        public string $locator,
        public int $chunkIndex,
        public ?int $pageNumber,
        public array $metadata = [],
    ) {
        if ($text === '' || strlen($text) > 16_384
            || trim($locator) === '' || strlen($locator) > 512
            || $chunkIndex < 0 || ($pageNumber !== null && $pageNumber < 1)
        ) {
            throw new InvalidArgumentException('Invalid or oversized chunk content or provenance.');
        }

        if (count($metadata) > 32) {
            throw new InvalidArgumentException('Chunk metadata exceeds the allowed field count.');
        }

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || in_array($key, ['document_id', 'document_version', 'vector_space', 'page_number', 'chunk_index', 'chunk_locator', 'content', 'retrieval_active'], true)
            ) {
                throw new InvalidArgumentException('Chunk metadata contains an invalid or reserved key.');
            }

            if (is_array($value)) {
                if (! array_is_list($value) || count($value) > 64) {
                    throw new InvalidArgumentException('Chunk metadata lists must be bounded lists.');
                }

                $values = $value;
            } else {
                $values = [$value];
            }

            foreach ($values as $item) {
                if (! is_scalar($item) || (is_string($item) && ($item === '' || strlen($item) > 2048))
                    || ((is_float($item)) && ! is_finite($item))
                ) {
                    throw new InvalidArgumentException('Chunk metadata values must be safe bounded scalars.');
                }
            }
        }
    }
}
