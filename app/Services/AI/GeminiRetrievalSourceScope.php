<?php

namespace App\Services\AI;

use InvalidArgumentException;
use RuntimeException;

/**
 * Batasan sumber retrieval Gemini yang dibangun sebelum generasi jawaban.
 *
 * Kandidat dari {@see GeminiRetrievalEligibilityService} adalah satu-satunya
 * sumber yang sah: kandidat kosong berarti tidak ada tool dan pemanggil wajib
 * berhenti tanpa memanggil Gemini, sedangkan identitas yang tidak dapat
 * dibuktikan (store ganda atau literal metadata tidak kanonik) gagal tertutup.
 * Scope tidak pernah menyempitkan daftar kandidat diam-diam; registry yang
 * janggal harus terlihat sebagai kegagalan, bukan jawaban yang tampak normal.
 *
 * Tool yang dihasilkan hanya berlaku bersama provider koneksi File Search
 * ({@see GeminiFileSearchProvider}); provider lain menolaknya.
 */
final class GeminiRetrievalSourceScope
{
    public function __construct(
        private readonly GeminiRetrievalEligibilityService $eligibility,
    ) {}

    /**
     * Batasan retrieval untuk keadaan database saat ini, atau null bila tidak
     * ada sumber eligible sehingga tidak boleh ada permintaan File Search.
     */
    public function tool(): ?GeminiRetrievalSourceTool
    {
        $candidates = $this->eligibility->eligibleCandidates();

        if ($candidates->isEmpty()) {
            return null;
        }

        try {
            return new GeminiRetrievalSourceTool($candidates->all());
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Gemini retrieval source scope cannot be established.', 0, $exception);
        }
    }
}
