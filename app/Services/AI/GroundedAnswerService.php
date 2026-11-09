<?php

namespace App\Services\AI;

/**
 * Kontrak jawaban chatbot Admin yang bersumber dari dokumen pengetahuan terbit.
 *
 * Implementasi aktif dipilih lewat binding container. Gemini File Search adalah
 * backend retrieval target untuk jalur SOP, sedangkan
 * {@see DocumentGroundedAnswerService} (Qdrant) dipertahankan sebagai jalur
 * legacy sampai cutover terverifikasi. Bentuk hasil wajib sama agar kontrak
 * percakapan dan tampilan sitasi tidak berubah saat binding berganti.
 */
interface GroundedAnswerService
{
    /** Pesan abstain bersama untuk kedua implementasi: tidak ada dasar yang sah. */
    public const ABSTENTION = 'Saya belum dapat memastikan jawaban dari dokumen PDF terbit yang tersedia.';

    /**
     * @return array{answer: string, citations: list<array{source: string, code: string, title: string, version: string, locator: string, page: ?string, chunk: string}>}
     */
    public function answer(string $question): array;
}
