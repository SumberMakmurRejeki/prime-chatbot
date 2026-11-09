<?php

namespace App\Services\AI;

/**
 * Hasil satu operasi remote indeksasi beserta bukti provenance-nya.
 *
 * Identitas dokumen hanya dipakai bila Gemini benar-benar mengirimkannya.
 * Kredensial provenance hanya diisi oleh operasi impor: nilai itulah yang
 * menjadi bukti kredensial mana yang mengesahkan dokumen masuk ke store.
 * Pemeriksaan status (GET idempoten) tidak pernah menjadi bukti provenance.
 */
final readonly class GeminiIndexingReport
{
    public function __construct(
        public GeminiIndexingState $state,
        public string $operationName,
        public ?string $documentName,
        public ?int $credentialConfigurationId = null,
        public ?int $credentialRevisionId = null,
    ) {}
}
