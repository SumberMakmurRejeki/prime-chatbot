<?php

namespace App\Services\AI;

/**
 * Hasil satu pemeriksaan status operasi indeksasi.
 *
 * Identitas hanya dipakai bila Gemini benar-benar mengirimkannya; nilai yang
 * tidak ada tidak pernah dikarang di sini.
 */
final readonly class GeminiIndexingReport
{
    public function __construct(
        public GeminiIndexingState $state,
        public string $operationName,
        public ?string $documentName,
    ) {}
}
