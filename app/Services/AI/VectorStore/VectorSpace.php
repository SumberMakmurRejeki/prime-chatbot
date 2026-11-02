<?php

namespace App\Services\AI\VectorStore;

use InvalidArgumentException;

final readonly class VectorSpace
{
    public function __construct(
        public string $identity,
        public int $dimension,
        public DistanceMetric $distance,
    ) {
        if (trim($identity) === '' || strlen($identity) > 128 || $dimension < 1) {
            throw new InvalidArgumentException('Invalid vector-space contract.');
        }
    }
}
