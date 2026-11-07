<?php

namespace App\Services\AI;

use App\Jobs\IndexKnowledgeDocumentInGemini;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Indeksasi dokumen Knowledge Base ke Gemini File Search (jalur asinkron, opt-in).
 *
 * Jalur ini berdampingan dengan pipeline Qdrant legacy (`DocumentEmbeddingIndexingService`)
 * dan tidak menggantikannya: status registry `knowledge_documents`, publikasi, dan
 * retrieval chatbot tidak pernah diubah di sini. Aktivasi sepenuhnya lewat
 * config/gemini_file_search.php dan bawaannya mati.
 *
 * Aturan yang dijaga class ini:
 * - Identitas yang dipercaya adalah snapshot registry (kode, versi, checksum)
 *   saat penjadwalan; metadata dan kredensial provider tidak pernah jadi bukti.
 * - Operasi non-idempoten (unggah, impor) tidak pernah diulang otomatis bila
 *   hasilnya tidak pasti; baris masuk `unknown` dan menunggu keputusan operator.
 * - Kesiapan teknis indeks bukan otorisasi publikasi; tidak ada dokumen yang
 *   otomatis dipublikasikan setelah indeksasi selesai.
 */
final class GeminiDocumentIndexingService
{
    /** Klaim `uploading`/`importing` yang lebih tua dari ini dianggap proses mati. */
    private const STALE_CLAIM_SECONDS = 600;

    public function __construct(private readonly GeminiFileSearchOperations $operations) {}

    public function enabled(): bool
    {
        return (bool) config('gemini_file_search.indexing.enabled', false);
    }

    /**
     * Jadwalkan (atau lanjutkan) indeksasi satu dokumen registry.
     *
     * Aman dipanggil berulang: satu baris indeksasi per dokumen dan job antrean
     * unik per baris, jadi klik ganda maupun retry Admin tidak membuat unggahan
     * atau operasi impor kedua di Google.
     */
    public function schedule(KnowledgeDocument $document): void
    {
        if (! $this->enabled()) {
            return;
        }

        $store = $this->configuredStore();
        $blocker = $this->configurationBlocker($store);

        $index = KnowledgeDocumentGeminiIndex::query()->firstOrCreate(
            ['knowledge_document_id' => $document->id],
            $this->scheduledAttributes($document, $store, $blocker),
        );

        if ($blocker !== null) {
            // Konfigurasi bermasalah tidak boleh "diam saja": baris dicatat gagal
            // tanpa satu pun permintaan keluar, dan pemanggilan berikutnya yang
            // memakai konfigurasi benar dapat melanjutkan indeksasi.
            if (! $index->wasRecentlyCreated) {
                $this->blockForConfiguration($index, $blocker);
            }

            Log::warning('Gemini File Search indexing tidak dijalankan.', [
                'knowledge_document_id' => $document->id,
                'reason' => $blocker,
            ]);

            return;
        }

        if ($index->wasRecentlyCreated) {
            $this->dispatch($index);

            return;
        }

        match ($index->state) {
            GeminiDocumentIndexState::Queued, GeminiDocumentIndexState::Uploaded, GeminiDocumentIndexState::Pending => $this->dispatch($index),
            // Klaim yang masih hangat milik worker lain; klaim yang sudah basi
            // berarti worker mati dan hasilnya tidak diketahui.
            GeminiDocumentIndexState::Uploading, GeminiDocumentIndexState::Importing => $this->settleStaleClaim($index),
            GeminiDocumentIndexState::Failed => $this->restart($document, $index, (string) $store),
            // Completed tidak perlu apa-apa; Unknown menunggu keputusan operator
            // karena mengulang unggah/impor bukan operasi yang aman.
            GeminiDocumentIndexState::Completed, GeminiDocumentIndexState::Unknown => null,
        };
    }

    /**
     * Jalankan satu tahap indeksasi; hanya dipanggil dari job antrean.
     *
     * Setiap transisi state bersyarat pada state tercatat, sehingga dua worker
     * tidak pernah mengerjakan tahap yang sama secara bersamaan.
     */
    public function process(int $indexId, int $generation): GeminiIndexingContinuation
    {
        $index = KnowledgeDocumentGeminiIndex::query()->find($indexId);

        if ($index === null || $index->generation !== $generation) {
            // Job dari percobaan lama atau baris yang sudah hilang: basi, stop.
            return GeminiIndexingContinuation::Settled;
        }

        $document = $index->knowledgeDocument()->first();

        if ($document === null) {
            return $this->fail($index, $index->state, 'document_missing', 'Dokumen registry tidak ditemukan.');
        }

        if (! $this->identityMatches($index, $document)) {
            return $this->fail($index, $index->state, 'document_identity_changed', 'Identitas atau checksum dokumen berubah sejak indeksasi dijadwalkan.');
        }

        $store = $this->configuredStore();

        if ($store === null || $store !== $index->store_id) {
            // Melanjutkan ke store lain akan menulis dokumen ke tempat yang tidak
            // pernah diminta Admin; lebih baik berhenti dengan kegagalan pasti.
            return $this->fail($index, $index->state, 'store_changed', 'Store Gemini File Search tujuan tidak lagi sama dengan saat penjadwalan.');
        }

        return match ($index->state) {
            GeminiDocumentIndexState::Queued => $this->upload($index, $document),
            GeminiDocumentIndexState::Uploaded => $this->import($index),
            GeminiDocumentIndexState::Pending => $this->inspect($index),
            GeminiDocumentIndexState::Uploading, GeminiDocumentIndexState::Importing => $this->markUnknown(
                $index,
                $index->state,
                'outcome_unknown',
                'Percobaan sebelumnya tidak tuntas; hasil di Google tidak diketahui dan tidak diulang otomatis.',
            ),
            GeminiDocumentIndexState::Completed, GeminiDocumentIndexState::Failed, GeminiDocumentIndexState::Unknown => GeminiIndexingContinuation::Settled,
        };
    }

    /**
     * Catat habisnya batas pemeriksaan status tanpa mengubah state `pending`;
     * baris tetap dapat dilanjutkan (tanpa unggah ulang) lewat penjadwalan lagi.
     */
    public function markStatusChecksExhausted(int $indexId, int $generation): void
    {
        KnowledgeDocumentGeminiIndex::query()
            ->whereKey($indexId)
            ->where('generation', $generation)
            ->where('state', GeminiDocumentIndexState::Pending->value)
            ->update([
                'safe_error_code' => 'status_check_exhausted',
                'safe_error_message' => 'Batas pemeriksaan status tercapai; indeksasi dapat dilanjutkan tanpa unggah ulang.',
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function upload(KnowledgeDocumentGeminiIndex $index, KnowledgeDocument $document): GeminiIndexingContinuation
    {
        if ($index->google_file_id !== null) {
            // Berkas sudah pernah terunggah pada percobaan sebelumnya: jangan
            // mengirim konten yang sama ke Google untuk kedua kali.
            return $this->import($index);
        }

        if (! Storage::disk('local')->exists($document->storage_path)) {
            return $this->fail($index, GeminiDocumentIndexState::Queued, 'source_missing', 'Berkas PDF sumber tidak ditemukan di storage privat.');
        }

        // Klaim state sebelum request keluar; bila worker mati setelah ini,
        // baris berhenti di `uploading` dan tidak mengunggah ulang tanpa izin.
        if (! $this->advance($index, GeminiDocumentIndexState::Queued, ['state' => GeminiDocumentIndexState::Uploading->value])) {
            return GeminiIndexingContinuation::Settled;
        }

        try {
            $fileId = $this->operations->uploadPdf(Storage::disk('local')->path($document->storage_path));
        } catch (Throwable $exception) {
            // Setiap kegagalan setelah klaim diperlakukan sebagai hasil tak pasti:
            // unggah tidak idempoten, jadi aman-nya menunggu pemeriksaan manual.
            $this->logFailure('Gemini File Search upload failed.', $document->id, $exception);

            return $this->markUnknown($index, GeminiDocumentIndexState::Uploading, 'upload_outcome_unknown', 'Unggahan ke Google tidak dapat dipastikan; indeksasi menunggu pemeriksaan manual.');
        }

        if (! $this->advance($index, GeminiDocumentIndexState::Uploading, [
            'state' => GeminiDocumentIndexState::Uploaded->value,
            'google_file_id' => $fileId,
        ])) {
            return GeminiIndexingContinuation::Settled;
        }

        return $this->import($index);
    }

    private function import(KnowledgeDocumentGeminiIndex $index): GeminiIndexingContinuation
    {
        if ($index->google_file_id === null) {
            return $this->fail($index, GeminiDocumentIndexState::Uploaded, 'file_identity_missing', 'Identitas berkas Google tidak tersimpan sehingga impor tidak dapat dilanjutkan.');
        }

        if (! $this->advance($index, GeminiDocumentIndexState::Uploaded, ['state' => GeminiDocumentIndexState::Importing->value])) {
            return GeminiIndexingContinuation::Settled;
        }

        $metadata = new GeminiDocumentMetadata($index->document_code, $index->document_version);

        try {
            $report = $this->operations->importFile($index->store_id, $index->google_file_id, $metadata);
        } catch (Throwable $exception) {
            $this->logFailure('Gemini File Search import failed.', $index->knowledge_document_id, $exception);

            return $this->markUnknown($index, GeminiDocumentIndexState::Importing, 'import_outcome_unknown', 'Hasil permintaan impor ke Google tidak diketahui; operasi tidak diulang otomatis.');
        }

        return match ($report->state) {
            GeminiIndexingState::Completed => $this->complete($index, GeminiDocumentIndexState::Importing, $report),
            GeminiIndexingState::Failed => $this->fail($index, GeminiDocumentIndexState::Importing, 'import_rejected', 'Google melaporkan impor dokumen gagal.'),
            GeminiIndexingState::Pending => $this->stashOperation($index, $report),
        };
    }

    private function inspect(KnowledgeDocumentGeminiIndex $index): GeminiIndexingContinuation
    {
        $operationName = $index->operation_name;

        if ($operationName === null) {
            // Status tidak dapat diperiksa tanpa mengulang impor yang tidak
            // idempoten, jadi baris berhenti di `unknown`.
            return $this->markUnknown($index, GeminiDocumentIndexState::Pending, 'operation_identity_missing', 'Identitas operasi impor tidak tersimpan; status tidak dapat diperiksa tanpa mengulang impor.');
        }

        try {
            $report = $this->operations->inspectIndexing($operationName);
        } catch (Throwable $exception) {
            $this->logFailure('Gemini File Search indexing status check failed.', $index->knowledge_document_id, $exception);

            // GET status idempoten: aman diulang sampai batas percobaan job.
            $this->advance($index, GeminiDocumentIndexState::Pending, ['last_checked_at' => now()]);

            return GeminiIndexingContinuation::RetryStatusRead;
        }

        return match ($report->state) {
            GeminiIndexingState::Completed => $this->complete($index, GeminiDocumentIndexState::Pending, $report),
            GeminiIndexingState::Failed => $this->fail($index, GeminiDocumentIndexState::Pending, 'remote_failed', 'Google melaporkan indeksasi gagal.'),
            GeminiIndexingState::Pending => $this->awaitRemoteCompletion($index),
        };
    }

    /**
     * Simpan identitas operasi impor SEBELUM job kembali ke antrean, supaya
     * pemeriksaan berikutnya tidak pernah mengirim impor baru.
     */
    private function stashOperation(KnowledgeDocumentGeminiIndex $index, GeminiIndexingReport $report): GeminiIndexingContinuation
    {
        if (! $this->advance($index, GeminiDocumentIndexState::Importing, [
            'state' => GeminiDocumentIndexState::Pending->value,
            'operation_name' => $report->operationName,
            'last_checked_at' => now(),
        ])) {
            return GeminiIndexingContinuation::Settled;
        }

        return GeminiIndexingContinuation::AwaitRemoteCompletion;
    }

    private function awaitRemoteCompletion(KnowledgeDocumentGeminiIndex $index): GeminiIndexingContinuation
    {
        $this->advance($index, GeminiDocumentIndexState::Pending, ['last_checked_at' => now()]);

        return GeminiIndexingContinuation::AwaitRemoteCompletion;
    }

    private function complete(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $from, GeminiIndexingReport $report): GeminiIndexingContinuation
    {
        // Identitas dokumen hanya diterima bila prefix `fileSearchStores/{store}`
        // cocok dengan store tujuan yang disnapshot; metadata provider tidak
        // pernah menjadi bukti identitas. Ini lapisan kedua setelah gateway.
        if ($report->documentName === null || ! str_starts_with($report->documentName, $index->store_id.'/documents/')) {
            return $this->fail($index, $from, 'document_store_mismatch', 'Identitas dokumen Google tidak terverifikasi pada store tujuan.');
        }

        // "Selesai" di sini hanya berarti indeks teknis siap; publikasi tetap
        // keputusan Admin terpisah dan tidak pernah dipicu dari sini.
        $this->advance($index, $from, [
            'state' => GeminiDocumentIndexState::Completed->value,
            'google_document_name' => $report->documentName,
            'last_checked_at' => now(),
            'safe_error_code' => null,
            'safe_error_message' => null,
        ]);

        return GeminiIndexingContinuation::Settled;
    }

    private function restart(KnowledgeDocument $document, KnowledgeDocumentGeminiIndex $index, string $store): void
    {
        // generation naik agar job lama yang masih tersisa di antrean langsung
        // dianggap basi saat dieksekusi, dan berkas yang sudah terunggah dipakai
        // ulang supaya tidak ada unggahan kedua untuk konten yang sama.
        if (! $this->advance($index, GeminiDocumentIndexState::Failed, [
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => $store,
            'state' => ($index->google_file_id === null ? GeminiDocumentIndexState::Queued : GeminiDocumentIndexState::Uploaded)->value,
            'generation' => $index->generation + 1,
            'operation_name' => null,
            'google_document_name' => null,
            'safe_error_code' => null,
            'safe_error_message' => null,
            'last_checked_at' => null,
        ])) {
            return;
        }

        $this->dispatch($index);
    }

    private function settleStaleClaim(KnowledgeDocumentGeminiIndex $index): void
    {
        if ($index->updated_at !== null && $index->updated_at->gt(now()->subSeconds(self::STALE_CLAIM_SECONDS))) {
            // Masih ada kemungkinan worker aktif; jangan ganggu klaimnya.
            return;
        }

        // Worker dianggap mati di tengah operasi non-idempoten: hasil di Google
        // tidak diketahui, jadi tidak ada pengulangan otomatis apa pun.
        $this->markUnknown($index, $index->state, 'stale_attempt', 'Percobaan indeksasi sebelumnya berhenti tanpa hasil pasti.');
    }

    /**
     * @return array<string, mixed> atribut awal baris indeksasi
     */
    private function scheduledAttributes(KnowledgeDocument $document, ?string $store, ?string $blocker): array
    {
        return [
            'document_code' => $document->document_code,
            'document_version' => $document->version,
            'document_checksum' => $document->checksum,
            'store_id' => $store,
            'state' => ($blocker === null ? GeminiDocumentIndexState::Queued : GeminiDocumentIndexState::Failed)->value,
            'generation' => 1,
            'safe_error_code' => $blocker,
            'safe_error_message' => $blocker === null ? null : $this->configurationMessage($blocker),
        ];
    }

    /**
     * Kode blokir konfigurasi, atau NULL bila penjadwalan boleh berjalan.
     */
    private function configurationBlocker(?string $store): ?string
    {
        if ($store === null) {
            return 'configuration_invalid';
        }

        // Queue sync menjalankan permintaan Google di dalam request Admin, jadi
        // konfigurasi itu harus ditolak sebelum ada pekerjaan remote.
        return $this->asynchronousQueueAvailable() ? null : 'queue_sync_unsafe';
    }

    private function asynchronousQueueAvailable(): bool
    {
        return config('queue.connections.'.config('queue.default', '').'.driver') !== 'sync';
    }

    private function configurationMessage(string $code): string
    {
        return match ($code) {
            'configuration_invalid' => 'Store Gemini File Search belum dikonfigurasi atau identitasnya tidak valid.',
            'queue_sync_unsafe' => 'Queue driver sync tidak aman untuk indeksasi Gemini; gunakan queue asinkron.',
            default => 'Konfigurasi indeksasi Gemini tidak valid.',
        };
    }

    private function blockForConfiguration(KnowledgeDocumentGeminiIndex $index, string $blocker): void
    {
        if (! in_array($index->state, [
            GeminiDocumentIndexState::Queued,
            GeminiDocumentIndexState::Uploaded,
            GeminiDocumentIndexState::Pending,
            GeminiDocumentIndexState::Failed,
        ], true)) {
            // Completed jangan dimundurkan; klaim aktif dan hasil tak pasti
            // tidak boleh ditimpa oleh perubahan konfigurasi.
            return;
        }

        $this->advance($index, $index->state, [
            'state' => GeminiDocumentIndexState::Failed->value,
            'safe_error_code' => $blocker,
            'safe_error_message' => $this->configurationMessage($blocker),
            'last_checked_at' => now(),
        ]);
    }

    /**
     * Store tujuan dari konfigurasi, dinormalisasi ke bentuk resource penuh;
     * NULL berarti konfigurasi belum menetapkan store yang valid.
     */
    private function configuredStore(): ?string
    {
        $configured = trim((string) config('gemini_file_search.indexing.store', ''));

        if ($configured === '') {
            return null;
        }

        $store = str_starts_with($configured, 'fileSearchStores/') ? $configured : 'fileSearchStores/'.$configured;

        return preg_match('#^fileSearchStores/[A-Za-z0-9._-]+$#', $store) === 1 ? $store : null;
    }

    private function identityMatches(KnowledgeDocumentGeminiIndex $index, KnowledgeDocument $document): bool
    {
        return $index->document_code === $document->document_code
            && $index->document_version === $document->version
            && hash_equals((string) $index->document_checksum, (string) $document->checksum);
    }

    private function dispatch(KnowledgeDocumentGeminiIndex $index): void
    {
        // Setelah commit agar job tidak pernah membaca baris yang belum tersimpan;
        // koneksi mengikuti queue default aplikasi seperti job registry legacy.
        IndexKnowledgeDocumentInGemini::dispatch($index->id, $index->generation)->afterCommit();
    }

    /**
     * Transisi state bersyarat: tulisan hanya diterima bila state baris masih
     * `$from`, sehingga dua worker tidak pernah menginjak tahap yang sama.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function advance(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $from, array $attributes): bool
    {
        $updated = KnowledgeDocumentGeminiIndex::query()
            ->whereKey($index->id)
            ->where('state', $from->value)
            ->update($attributes + ['updated_at' => now()]);

        if ($updated === 1) {
            $index->refresh();
        }

        return $updated === 1;
    }

    private function markUnknown(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $from, string $code, string $message): GeminiIndexingContinuation
    {
        $this->advance($index, $from, [
            'state' => GeminiDocumentIndexState::Unknown->value,
            'safe_error_code' => $code,
            'safe_error_message' => $message,
            'last_checked_at' => now(),
        ]);

        return GeminiIndexingContinuation::Settled;
    }

    private function fail(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $from, string $code, string $message): GeminiIndexingContinuation
    {
        $this->advance($index, $from, [
            'state' => GeminiDocumentIndexState::Failed->value,
            'safe_error_code' => $code,
            'safe_error_message' => $message,
            'last_checked_at' => now(),
        ]);

        return GeminiIndexingContinuation::Settled;
    }

    private function logFailure(string $message, int $documentId, Throwable $exception): void
    {
        // Hanya kelas exception dicatat; pesan provider bisa memuat detail internal.
        Log::error($message, ['knowledge_document_id' => $documentId, 'exception' => $exception::class]);
    }
}
