<?php

namespace App\Services\AI;

/**
 * Hasil satu probe kemampuan provider. Hanya kategori aman dan panjang vektor
 * embedding yang boleh keluar dari batas ini; teks/vektor mentah tidak pernah
 * dibawa ke luar.
 */
final readonly class ProviderProbeResult
{
    public function __construct(
        public ProviderTestCategory $category,
        public ?int $embeddingDimensions = null,
        public bool $superseded = false,
    ) {}

    public static function success(?int $embeddingDimensions = null): self
    {
        return new self(ProviderTestCategory::Success, $embeddingDimensions);
    }

    public static function failure(ProviderTestCategory $category): self
    {
        return new self($category);
    }

    public static function superseded(): self
    {
        return new self(ProviderTestCategory::Success, superseded: true);
    }

    public function passed(): bool
    {
        return ! $this->superseded && $this->category->passed();
    }
}
