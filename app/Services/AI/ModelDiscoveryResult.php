<?php

namespace App\Services\AI;

final readonly class ModelDiscoveryResult
{
    /** @param list<string> $modelIds */
    public function __construct(
        public array $modelIds,
        public ?string $error = null,
    ) {}

    /** @param list<string> $modelIds */
    public static function success(array $modelIds): self
    {
        return new self($modelIds);
    }

    public static function failure(string $message): self
    {
        return new self([], $message);
    }

    public function succeeded(): bool
    {
        return $this->error === null;
    }
}
