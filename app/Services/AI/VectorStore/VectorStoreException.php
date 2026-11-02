<?php

namespace App\Services\AI\VectorStore;

use RuntimeException;

final class VectorStoreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly VectorStoreError $kind,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
