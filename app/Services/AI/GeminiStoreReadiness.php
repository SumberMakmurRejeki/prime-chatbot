<?php

namespace App\Services\AI;

/**
 * Hasil satu pemeriksaan read-only akses File Search Store (T1).
 *
 * Hasil ini hanya membuktikan store yang dikonfigurasi dapat dibaca dengan
 * kredensial chat aktif; ia bukan bukti RAG siap dan bukan izin aktivasi
 * indeksasi. `reasonCode` adalah bagian kontrak yang stabil dan aman untuk UI
 * atau log: tidak pernah memuat kredensial, endpoint, atau teks error provider.
 */
final readonly class GeminiStoreReadiness
{
    public function __construct(
        public GeminiStoreReadinessState $state,
        public string $reasonCode,
        public ?string $storeName = null,
    ) {}

    public function accessible(): bool
    {
        return $this->state === GeminiStoreReadinessState::Accessible;
    }
}
