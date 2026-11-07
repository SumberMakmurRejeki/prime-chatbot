<?php

namespace App\Jobs;

use App\Services\AI\GeminiDocumentIndexingService;
use App\Services\AI\GeminiIndexingContinuation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Throwable;

/**
 * Indeksasi Gemini File Search untuk satu baris indeks dokumen.
 *
 * Unik per baris indeksasi (bukan per dokumen versi baru) dan dibatasi jumlah
 * percobaan: setiap percobaan mengerjakan paling banyak satu tahap remote
 * (unggah, impor, atau satu pemeriksaan status), lalu kembali ke antrean bila
 * Google masih memproses. Orkestrasi dan pengamanan data ada di
 * {@see GeminiDocumentIndexingService}; job ini hanya penggerak antrean.
 */
#[UniqueFor(3600)]
final class IndexKnowledgeDocumentInGemini implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $indexId,
        public readonly int $generation,
    ) {}

    /**
     * Kunci unikalitas per baris DAN generation: retry membuat generation baru,
     * jadi job lama yang masih memegang kunci tidak boleh menggagalkan
     * (membuang senyap) dispatch job hasil restart.
     */
    public function uniqueId(): string
    {
        return $this->indexId.':'.$this->generation;
    }

    /**
     * Execute the job.
     */
    public function handle(GeminiDocumentIndexingService $indexing): void
    {
        $continuation = $indexing->process($this->indexId, $this->generation);

        if ($continuation !== GeminiIndexingContinuation::Settled) {
            // Hanya tahap idempoten yang kembali ke antrean; jeda membatasi
            // laju permintaan ke Google tanpa polling di dalam satu job.
            $this->release($continuation->releaseDelay());
        }
    }

    /**
     * Percobaan habis: finalisasi state baris indeksasi supaya tidak ada baris
     * yang tampak masih menunggu diproses setelah job gagal permanen.
     */
    public function failed(?Throwable $exception): void
    {
        app(GeminiDocumentIndexingService::class)->markRetriesExhausted($this->indexId, $this->generation);
    }
}
