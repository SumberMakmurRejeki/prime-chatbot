<?php

namespace App\Services\AI;

use App\Jobs\IndexKnowledgeDocumentInGemini;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;
use RuntimeException;
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
 * - Setiap permintaan tulis Google baru (unggah/impor) didahului pemeriksaan
 *   akses store read-only; store yang tidak accessible tidak pernah menerima
 *   unggahan, dan kegagalan akses dicatat tanpa mengubah publikasi dokumen.
 * - Operasi non-idempoten (unggah, impor) tidak pernah diulang otomatis bila
 *   hasilnya tidak pasti; baris masuk `unknown` dan menunggu keputusan operator.
 * - Kesiapan teknis indeks bukan otorisasi publikasi; tidak ada dokumen yang
 *   otomatis dipublikasikan setelah indeksasi selesai.
 */
final class GeminiDocumentIndexingService
{
    /** Klaim `uploading`/`importing` yang lebih tua dari ini dianggap proses mati. */
    private const STALE_CLAIM_SECONDS = 600;

    public function __construct(
        private readonly GeminiFileSearchOperations $operations,
        private readonly GeminiRetrievalEligibilityService $eligibility,
        private readonly GeminiFileSearchConnection $connection,
    ) {}

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
     * Pulihkan indeksasi satu dokumen dengan generation baru. Publikasi dan
     * pemrosesan registry tidak pernah disentuh di sini.
     *
     * Batas aman yang dijaga:
     * - State dibaca ulang di dalam transaksi dengan lock, sehingga job yang
     *   berjalan bersamaan tidak dapat menyelip di antara keputusan dan
     *   perubahan generation.
     * - Generation baru hanya dibuat untuk baris `failed` yang tidak menyimpan
     *   satu pun identitas resource Google (berkas, operasi, dokumen): semua
     *   state lain tidak dipulihkan di sini. Baris yang masih menunjuk dokumen
     *   di store tidak diubah karena resource lama tidak pernah dihapus
     *   otomatis; menggantinya hanya menggandakan dokumen.
     * - Identitas operasi yang tersimpan (impor yang masih menggantung hasil
     *   tidak pastinya) hanya dilanjutkan lewat pemeriksaan status GET pada
     *   store yang sama; impor kedua tidak pernah dikirim dan provenance lama
     *   tidak pernah ditulis ulang.
     * - Sebelum satu pun permintaan Google, PDF registry harus ada dan byte-nya
     *   cocok dengan checksum registry, serta kredensial Gemini harus dapat
     *   diresolusi tanpa jaringan; kegagalan apa pun dilempar tanpa mengubah
     *   state baris.
     * - Klaim `uploading`/`importing` hangat ditolak; klaim basi dipindahkan ke
     *   `unknown` (hasil tulis tetap tidak diulang).
     *
     * @throws DomainException bila konfigurasi, sumber, kredensial, atau state
     *                         baris membuat pemulihan tidak aman atau tidak perlu
     */
    public function recover(KnowledgeDocument $document): KnowledgeDocumentGeminiIndex
    {
        if (! $this->enabled()) {
            throw new DomainException('Indeksasi Gemini File Search tidak aktif; pemulihan tidak dijalankan.');
        }

        $store = $this->configuredStore();
        $blocker = $this->configurationBlocker($store);

        if ($blocker !== null) {
            throw new DomainException($this->configurationMessage($blocker));
        }

        $result = DB::transaction(function () use ($document, $store): KnowledgeDocumentGeminiIndex|DomainException {
            $currentDocument = KnowledgeDocument::query()->whereKey($document->getKey())->lockForUpdate()->first();
            $index = KnowledgeDocumentGeminiIndex::query()
                ->where('knowledge_document_id', $document->getKey())
                ->lockForUpdate()
                ->first();

            if ($currentDocument === null || $index === null) {
                throw new DomainException('Dokumen atau indeksasi Gemini tidak ditemukan.');
            }

            if (! $this->identityMatches($index, $currentDocument)) {
                throw new DomainException('Identitas atau checksum dokumen berubah sejak indeksasi dijadwalkan; pemulihan ditolak.');
            }

            if (in_array($index->state, [GeminiDocumentIndexState::Uploading, GeminiDocumentIndexState::Importing], true)) {
                // Exception dilempar sesudah commit agar penandaan klaim basi
                // sebagai unknown tidak ikut dibatalkan.
                return $this->recoverInFlight($index);
            }

            if ($index->state === GeminiDocumentIndexState::Completed) {
                $this->recoverCompletedIndex($currentDocument);
            }

            // Identitas operasi yang tersimpan hanya dilanjutkan lewat GET.
            // Jangan menebak scope dari metadata atau mengirim impor kedua.
            if ($index->operation_name !== null) {
                if ($index->store_id !== $store
                    || preg_match('#^'.preg_quote((string) $store, '#').'/operations/[A-Za-z0-9._~-]+$#', $index->operation_name) !== 1
                    || ! in_array($index->state, [GeminiDocumentIndexState::Pending, GeminiDocumentIndexState::Unknown], true)) {
                    throw new DomainException('Identitas operasi lama belum aman untuk dilanjutkan.');
                }

                $this->advance($index, $index->state, ['state' => GeminiDocumentIndexState::Pending->value]);

                return $this->resumeGeneration($index);
            }

            if ($index->state !== GeminiDocumentIndexState::Failed || $this->hasRemoteIdentity($index)) {
                throw new DomainException($this->unverifiableOutcomeMessage());
            }

            // Kolom baru tetap NULL pada generation lama; tidak ada error code
            // atau timestamp yang dapat membuktikan bahwa generation itu aman
            // untuk diunggah ulang.
            if ($index->remote_write_evidence !== GeminiRemoteWriteEvidence::NotStarted) {
                throw new DomainException($this->unknownWriteEvidenceMessage());
            }

            $sourceProblem = $this->sourceProblem($currentDocument);

            if ($sourceProblem !== null) {
                throw new DomainException($this->sourceMessage($sourceProblem));
            }

            try {
                // Resolusi lokal memvalidasi satu revisi Gemini aktif, secret,
                // dan endpoint; provenance tetap hanya lahir dari request impor.
                $this->connection->resolveWithCredential();
            } catch (Throwable $exception) {
                throw new DomainException('Kredensial Gemini aktif tidak terverifikasi; pemulihan ditolak.', 0, $exception);
            }

            return $this->recoverFailedGeneration($currentDocument, $index, (string) $store);
        });

        if ($result instanceof DomainException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Jalankan satu tahap indeksasi; hanya dipanggil dari job antrean.
     *
     * Setiap transisi bersyarat pada state dan generation yang tercatat,
     * sehingga job lama tidak menginjak tahap milik job baru.
     */
    public function process(int $indexId, int $generation): GeminiIndexingContinuation
    {
        $index = KnowledgeDocumentGeminiIndex::query()->find($indexId);

        if ($index === null || $index->generation !== $generation) {
            // Job dari percobaan lama atau baris yang sudah hilang: basi, stop.
            return GeminiIndexingContinuation::Settled;
        }

        if (in_array($index->state, [
            GeminiDocumentIndexState::Completed,
            GeminiDocumentIndexState::Failed,
            GeminiDocumentIndexState::Unknown,
        ], true)) {
            return GeminiIndexingContinuation::Settled;
        }

        if (in_array($index->state, [GeminiDocumentIndexState::Uploading, GeminiDocumentIndexState::Importing], true)) {
            return $this->markUnknown(
                $index,
                $index->state,
                'outcome_unknown',
                'Percobaan sebelumnya tidak tuntas; hasil di Google tidak diketahui dan tidak diulang otomatis.',
            );
        }

        $document = $index->knowledgeDocument()->first();

        if ($document === null) {
            return $this->fail($index, $index->state, 'document_missing', 'Dokumen registry tidak ditemukan.');
        }

        // Jangan biarkan pemeriksaan identitas atau konfigurasi menghapus
        // klasifikasi hasil tulis yang tidak pasti menjadi `failed`.
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

    /**
     * Titik akhir job antrean setelah semua percobaan habis.
     *
     * `pending` memakai perilaku lama: pemeriksaan status adalah GET idempoten,
     * jadi baris tetap `pending` dengan kode `status_check_exhausted` dan dapat
     * dilanjutkan tanpa unggah ulang. Baris `queued`/`uploaded` yang habis
     * percobaan berarti satu-satunya kegagalan adalah gerbang akses store
     * (RetryStatusRead) sebelum permintaan tulis dikirim, sehingga baris itu
     * bukan pekerjaan yang masih berjalan dan harus difinalisasi gagal pasti.
     * Hasil tulis yang tidak pasti (`uploading`, `importing`, `unknown`) serta
     * baris yang sudah tuntas sengaja tidak disentuh.
     */
    public function markRetriesExhausted(int $indexId, int $generation): void
    {
        $this->markStatusChecksExhausted($indexId, $generation);
        $this->finalizeExhaustedAttempt($indexId, $generation, GeminiDocumentIndexState::Queued);
        $this->finalizeExhaustedAttempt($indexId, $generation, GeminiDocumentIndexState::Uploaded);
    }

    /**
     * Finalisasi baris `queued`/`uploaded` yang percobaannya habis tanpa satu
     * pun permintaan tulis baru. Identitas Google (file_id, operation_name)
     * dibiarkan apa adanya agar restart memakai berkas yang sama.
     */
    private function finalizeExhaustedAttempt(int $indexId, int $generation, GeminiDocumentIndexState $from): void
    {
        $uploaded = $from === GeminiDocumentIndexState::Uploaded;

        KnowledgeDocumentGeminiIndex::query()
            ->whereKey($indexId)
            ->where('generation', $generation)
            ->where('state', $from->value)
            ->update([
                'state' => GeminiDocumentIndexState::Failed->value,
                'safe_error_code' => 'indexing_attempts_exhausted',
                'safe_error_message' => $uploaded
                    ? 'Pemrosesan dokumen berhenti setelah beberapa percobaan. Berkas sudah diunggah; periksa koneksi AI, lalu coba lagi.'
                    : 'Pemrosesan dokumen berhenti setelah beberapa percobaan. Periksa koneksi AI, lalu coba lagi.',
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Gerbang sebelum satu permintaan tulis Google: hanya store `accessible`
     * dengan identitas yang cocok yang boleh melanjutkan.
     *
     * NULL berarti boleh lanjut. `$expectedState` adalah state baris yang
     * menjadi syarat transisi bersyarat di pemanggil, sehingga hasil gerbang
     * tidak pernah menimpa state yang berubah di tengah jalan.
     *
     * Pemetaan kegagalan mengikuti kontrak T1: gangguan sementara tetap dapat
     * diulang lewat antrean, sedangkan kredensial atau store yang salah adalah
     * kegagalan pasti tanpa permintaan tulis. Pemeriksaan memakai kredensial
     * revisi chat aktif yang sama dengan operasi berikutnya, dan store yang
     * diminta adalah snapshot `store_id` baris ini.
     */
    private function storeAccessBlocker(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $expectedState): ?GeminiIndexingContinuation
    {
        $readiness = $this->operations->checkStoreReadiness();

        if ($readiness->accessible()) {
            return null;
        }

        if ($readiness->state === GeminiStoreReadinessState::Unavailable) {
            // 429/5xx atau gangguan transport: belum ada permintaan tulis yang
            // terkirim, jadi baris tetap pada state-nya dan job dicoba lagi
            // sampai batas percobaan. Batas tersebut tidak memindahkan state:
            // baris tetap aman dijadwalkan ulang dan gerbang ini jalan lagi
            // sebelum ada unggahan. Seluruh kegagalan tulis lain di jalur ini
            // tetap berakhir `unknown`, bukan diulang.
            if (! $this->advance($index, $expectedState, [
                'safe_error_code' => $readiness->reasonCode,
                'safe_error_message' => 'Akses store Gemini File Search sedang tidak tersedia; belum ada unggahan yang terkirim.',
                'last_checked_at' => now(),
            ])) {
                return GeminiIndexingContinuation::Settled;
            }

            Log::warning('Akses store Gemini File Search belum tersedia; indeksasi ditunda.', [
                'knowledge_document_id' => $index->knowledge_document_id,
                'reason' => $readiness->reasonCode,
            ]);

            return GeminiIndexingContinuation::RetryStatusRead;
        }

        // Kredensial, store, atau identitas store tidak sah: bukan kondisi
        // sementara, sehingga tidak ada unggahan yang dicoba dan baris menunggu
        // perbaikan konfigurasi, bukan retry otomatis tanpa akhir.
        return $this->fail(
            $index,
            $expectedState,
            $readiness->reasonCode,
            $this->storeAccessMessage($readiness->state),
        );
    }

    private function storeAccessMessage(GeminiStoreReadinessState $state): string
    {
        return match ($state) {
            GeminiStoreReadinessState::AccessDenied => 'Akses ke store Gemini File Search ditolak; unggahan dibatalkan sampai kredensial diperbaiki.',
            GeminiStoreReadinessState::MissingStore => 'Store Gemini File Search tidak ditemukan; unggahan dibatalkan tanpa membuat store baru.',
            GeminiStoreReadinessState::InvalidResponse => 'Identitas store Gemini File Search tidak terverifikasi; unggahan dibatalkan.',
            default => 'Kredensial atau store Gemini File Search belum siap; unggahan dibatalkan.',
        };
    }

    private function upload(KnowledgeDocumentGeminiIndex $index, KnowledgeDocument $document): GeminiIndexingContinuation
    {
        if ($index->google_file_id !== null) {
            // Berkas sudah pernah terunggah pada percobaan sebelumnya: jangan
            // mengirim konten yang sama ke Google untuk kedua kali.
            return $this->import($index);
        }

        $sourceProblem = $this->sourceProblem($document);

        if ($sourceProblem !== null) {
            // Byte yang tidak cocok dengan registry tidak boleh masuk ke Google:
            // dokumen di store akan mewakili revisi yang salah.
            return $this->fail($index, GeminiDocumentIndexState::Queued, $sourceProblem, $this->sourceMessage($sourceProblem));
        }

        // Gerbang akses store tepat sebelum klaim dan permintaan unggah; baris
        // yang ditolak gerbang tidak pernah meninggalkan state `uploading`.
        $blocker = $this->storeAccessBlocker($index, GeminiDocumentIndexState::Queued);

        if ($blocker !== null) {
            return $blocker;
        }

        // Klaim state dan bukti tulis disimpan dalam satu update sebelum
        // request keluar: worker yang mati setelah ini meninggalkan bukti bahwa
        // unggahan mungkin sudah terkirim, dan bukti itu tidak pernah
        // diturunkan kembali pada generation yang sama.
        $claimed = $this->advance($index, GeminiDocumentIndexState::Queued, [
            'state' => GeminiDocumentIndexState::Uploading->value,
            'remote_write_evidence' => GeminiRemoteWriteEvidence::MayHaveStarted->value,
        ]);

        if (! $claimed) {
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

        if (! is_string($index->import_identity) || $index->import_identity === '') {
            // Tanpa identitas generasi, dokumen yang masuk store tidak dapat
            // dibedakan dari impor lain yang berbagi kode dan versi; impor
            // dibatalkan sebelum satu pun permintaan tulis dikirim.
            return $this->fail($index, GeminiDocumentIndexState::Uploaded, 'import_identity_missing', 'Identitas generasi impor tidak tersimpan sehingga impor tidak dapat dilanjutkan.');
        }

        // Impor adalah permintaan tulis tersendiri, jadi akses store diperiksa
        // lagi di sini: baris yang diunggah pada eksekusi sebelumnya tidak boleh
        // menulis ke store yang sudah tidak dapat diakses.
        $blocker = $this->storeAccessBlocker($index, GeminiDocumentIndexState::Uploaded);

        if ($blocker !== null) {
            return $blocker;
        }

        // Impor adalah permintaan tulis tersendiri: bukti tulis disimpan atomik
        // dengan klaim `importing` sebelum permintaan dikirim.
        $claimed = $this->advance($index, GeminiDocumentIndexState::Uploaded, [
            'state' => GeminiDocumentIndexState::Importing->value,
            'remote_write_evidence' => GeminiRemoteWriteEvidence::MayHaveStarted->value,
        ]);

        if (! $claimed) {
            return GeminiIndexingContinuation::Settled;
        }

        $metadata = new GeminiDocumentMetadata($index->document_code, $index->document_version, $index->import_identity);

        try {
            $report = $this->operations->importFile($index->store_id, $index->google_file_id, $metadata);
        } catch (Throwable $exception) {
            $this->logFailure('Gemini File Search import failed.', $index->knowledge_document_id, $exception);

            return $this->markUnknown($index, GeminiDocumentIndexState::Importing, 'import_outcome_unknown', 'Hasil permintaan impor ke Google tidak diketahui; operasi tidak diulang otomatis.');
        }

        // Provenance ditulis hanya di sini: satu-satunya operasi yang membuat
        // dokumen di store adalah impor ini, dan nilainya berasal dari resolusi
        // kredensial yang benar-benar mengirim request tersebut.
        $provenance = [
            'credential_configuration_id' => $report->credentialConfigurationId,
            'credential_revision_id' => $report->credentialRevisionId,
        ];

        return match ($report->state) {
            GeminiIndexingState::Completed => $this->complete($index, GeminiDocumentIndexState::Importing, $report, $provenance),
            GeminiIndexingState::Failed => $this->fail($index, GeminiDocumentIndexState::Importing, 'import_rejected', 'Google melaporkan impor dokumen gagal.'),
            GeminiIndexingState::Pending => $this->stashOperation($index, $report, $provenance),
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

            // Pelanggaran kontrak respons (AiException biasa dari gateway
            // aman) bersifat permanen: GET ulang akan menerima payload yang
            // sama, jadi polling dihentikan. Baris masuk `unknown`, bukan
            // `failed`, karena restart otomatis bisa mengirim impor kedua ke
            // store sementara hasil impor aslinya tidak dapat dipercaya.
            if ($this->isContractViolation($exception)) {
                return $this->markUnknown(
                    $index,
                    GeminiDocumentIndexState::Pending,
                    'provider_response_invalid',
                    'Respons status indeksasi Gemini tidak valid; pemeriksaan otomatis dihentikan.',
                );
            }

            // GET status idempoten: aman diulang sampai batas percobaan job.
            if (! $this->advance($index, GeminiDocumentIndexState::Pending, ['last_checked_at' => now()])) {
                return GeminiIndexingContinuation::Settled;
            }

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
     *
     * @param  array<string, int|null>  $provenance
     */
    private function stashOperation(KnowledgeDocumentGeminiIndex $index, GeminiIndexingReport $report, array $provenance): GeminiIndexingContinuation
    {
        if (! $this->advance($index, GeminiDocumentIndexState::Importing, $provenance + [
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
        if (! $this->advance($index, GeminiDocumentIndexState::Pending, ['last_checked_at' => now()])) {
            return GeminiIndexingContinuation::Settled;
        }

        return GeminiIndexingContinuation::AwaitRemoteCompletion;
    }

    /**
     * @param  array<string, int|null>  $provenance  hanya diisi pada transisi impor
     */
    private function complete(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $from, GeminiIndexingReport $report, array $provenance = []): GeminiIndexingContinuation
    {
        // Identitas dokumen hanya diterima bila prefix `fileSearchStores/{store}`
        // cocok dengan store tujuan yang disnapshot; metadata provider tidak
        // pernah menjadi bukti identitas. Ini lapisan kedua setelah gateway.
        if ($report->documentName === null || ! str_starts_with($report->documentName, $index->store_id.'/documents/')) {
            return $this->fail($index, $from, 'document_store_mismatch', 'Identitas dokumen Google tidak terverifikasi pada store tujuan.');
        }

        // Pemeriksaan status (pending -> completed) tidak boleh menimpa bukti
        // kredensial impor; hanya transisi dari impor yang membawa provenance.
        $attributes = $from === GeminiDocumentIndexState::Importing ? $provenance : [];

        // "Selesai" di sini hanya berarti indeks teknis siap; publikasi tetap
        // keputusan Admin terpisah dan tidak pernah dipicu dari sini.
        $this->advance($index, $from, $attributes + [
            'state' => GeminiDocumentIndexState::Completed->value,
            'google_document_name' => $report->documentName,
            'last_checked_at' => now(),
            'safe_error_code' => null,
            'safe_error_message' => null,
        ]);

        return GeminiIndexingContinuation::Settled;
    }

    private function restart(KnowledgeDocument $document, KnowledgeDocumentGeminiIndex $index, string $store): ?KnowledgeDocumentGeminiIndex
    {
        // Arsip dan transisi harus satu transaksi: history tanpa pergantian
        // generation adalah bukti menyesatkan. Baris dikunci agar snapshot
        // diambil dari nilai yang benar-benar digantikan, bukan dari copy
        // yang mungkin basi di memori pemanggil.
        $restartedIndex = DB::transaction(function () use ($document, $index, $store): ?KnowledgeDocumentGeminiIndex {
            $current = KnowledgeDocumentGeminiIndex::query()
                ->whereKey($index->id)
                ->where('state', GeminiDocumentIndexState::Failed->value)
                ->where('generation', $index->generation)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            // Generation baru hanya aman bila generation sebelumnya terbukti
            // belum menulis dan tidak menyimpan identitas resource Google.
            // Resource yang masih ada tidak boleh diganti atau diimpor ulang
            // otomatis karena keduanya dapat meninggalkan dokumen duplikat.
            if ($this->hasRemoteIdentity($current)
                || $current->remote_write_evidence !== GeminiRemoteWriteEvidence::NotStarted) {
                return null;
            }

            $this->archiveGeneration($current);

            $restarted = $this->advance($current, GeminiDocumentIndexState::Failed, [
                'document_code' => $document->document_code,
                'document_version' => $document->version,
                'document_checksum' => $document->checksum,
                'store_id' => $store,
                'state' => GeminiDocumentIndexState::Queued->value,
                // Generation baru menerima bukti awalnya sendiri; bukti generasi
                // lama tetap hidup di baris history.
                'remote_write_evidence' => GeminiRemoteWriteEvidence::NotStarted->value,
                'generation' => $current->generation + 1,
                'operation_name' => null,
                'google_document_name' => null,
                // Generasi baru berarti impor baru: identitas dan bukti kredensial
                // impor lama tidak boleh dipakai ulang untuk dokumen berikutnya.
                'import_identity' => $this->newImportIdentity(),
                'credential_configuration_id' => null,
                'credential_revision_id' => null,
                'safe_error_code' => null,
                'safe_error_message' => null,
                'last_checked_at' => null,
            ]);

            throw_if(! $restarted, RuntimeException::class, 'Transisi generation Gemini gagal setelah baris dikunci.');

            return $current;
        });

        if ($restartedIndex === null) {
            return null;
        }

        $this->dispatch($restartedIndex);

        return $restartedIndex;
    }

    /**
     * Nilai diambil sebagai atribut mentah (bukan hasil cast) supaya state,
     * timestamp, dan provenance tersimpan persis seperti di baris aktif —
     * termasuk NULL, yang tidak boleh diubah menjadi nilai lain.
     */
    private function archiveGeneration(KnowledgeDocumentGeminiIndex $index): void
    {
        $snapshot = Arr::only($index->getAttributes(), [
            'knowledge_document_id', 'document_code', 'document_version', 'document_checksum',
            'store_id', 'google_file_id', 'operation_name', 'google_document_name', 'state',
            'generation', 'credential_configuration_id', 'credential_revision_id', 'import_identity',
            'remote_write_evidence', 'safe_error_code', 'safe_error_message', 'last_checked_at',
        ]);

        DB::table('knowledge_document_gemini_indexing_generations')->insert([
            ...$snapshot,
            'knowledge_document_gemini_indexing_id' => $index->getKey(),
            'current_created_at' => $index->getRawOriginal('created_at'),
            'current_updated_at' => $index->getRawOriginal('updated_at'),
            'archived_at' => now(),
        ]);
    }

    /**
     * Lanjutkan generation yang masih berjalan sendiri: `queued` belum menulis
     * apa pun ke Google, `uploaded` sudah memegang berkas tetapi belum pernah
     * meminta impor, dan `pending` hanya memeriksa status operasi via GET.
     */
    private function resumeGeneration(KnowledgeDocumentGeminiIndex $index): KnowledgeDocumentGeminiIndex
    {
        $this->dispatch($index);

        return $index;
    }

    /**
     * Generation `failed` baru boleh digantikan bila tidak ada satu pun jejak
     * resource Google: retry yang memakai berkas atau operasi lama berada di
     * jalur penjadwalan biasa, bukan di pemulihan ini.
     */
    private function recoverFailedGeneration(
        KnowledgeDocument $document,
        KnowledgeDocumentGeminiIndex $index,
        string $store,
    ): KnowledgeDocumentGeminiIndex {
        if ($this->hasRemoteIdentity($index)) {
            throw new DomainException('Baris indeksasi masih menyimpan identitas resource Google; generation baru ditolak untuk mencegah resource ganda.');
        }

        $restarted = $this->restart($document, $index, $store);

        if ($restarted === null) {
            throw new DomainException('Baris indeksasi berubah saat pemulihan berjalan; ulangi pemulihan setelah status dibaca ulang.');
        }

        return $restarted;
    }

    /**
     * Indeks `completed` tidak dipulihkan: dokumen lama tetap ada di store,
     * dan generation baru hanya akan menggandakannya tanpa cara membersihkan
     * yang lama. Indeks yang sudah terverifikasi sama sekali tidak perlu pulih.
     */
    private function recoverCompletedIndex(KnowledgeDocument $document): never
    {
        if ($this->eligibility->trustedIndexFor($document) !== null) {
            throw new DomainException('Indeks Gemini dokumen ini sudah terverifikasi; pemulihan tidak diperlukan.');
        }

        throw new DomainException('Generation lama masih memiliki dokumen di store Google dan tidak dihapus otomatis; generation baru ditolak untuk mencegah resource ganda.');
    }

    /**
     * Klaim `uploading`/`importing` berarti satu operasi tulis mungkin sedang
     * berjalan: klaim hangat ditolak, sedangkan klaim basi milik worker mati
     * dijadikan `unknown` dan tetap tidak diulang.
     */
    private function recoverInFlight(KnowledgeDocumentGeminiIndex $index): DomainException
    {
        $this->settleStaleClaim($index);

        return new DomainException(
            $index->state === GeminiDocumentIndexState::Unknown
                ? $this->unverifiableOutcomeMessage()
                : 'Percobaan indeksasi lain masih berjalan; pemulihan tidak dapat dipastikan aman.',
        );
    }

    private function unverifiableOutcomeMessage(): string
    {
        return 'Hasil generation lama tidak dapat dipastikan; pemulihan tidak dimulai tanpa operasi yang dapat diperiksa.';
    }

    /**
     * NULL berarti baris historis tanpa bukti, `may_have_started` berarti
     * mutasi mungkin sudah dikirim; keduanya tidak memenuhi syarat generation
     * baru yang aman.
     */
    private function unknownWriteEvidenceMessage(): string
    {
        return 'Bukti tulis Google pada generation lama tidak diketahui atau tidak menjamin keamanan; pemulihan ditolak tanpa unggahan baru.';
    }

    /** Identitas resource Google yang tersimpan menandai generation lama masih hidup di sana. */
    private function hasRemoteIdentity(KnowledgeDocumentGeminiIndex $index): bool
    {
        return $index->google_file_id !== null
            || $index->operation_name !== null
            || $index->google_document_name !== null;
    }

    /**
     * Masalah PDF asli, atau NULL bila berkas ada dan byte-nya masih cocok
     * dengan checksum registry.
     */
    private function sourceProblem(KnowledgeDocument $document): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($document->storage_path)) {
            return 'source_missing';
        }

        $checksum = hash_file('sha256', $disk->path($document->storage_path));

        if (! is_string($checksum) || ! hash_equals((string) $document->checksum, $checksum)) {
            return 'source_checksum_mismatch';
        }

        return null;
    }

    private function sourceMessage(string $code): string
    {
        return match ($code) {
            'source_missing' => 'Berkas PDF sumber tidak ditemukan di storage privat; tidak ada permintaan Google yang dikirim.',
            'source_checksum_mismatch' => 'Checksum berkas PDF sumber tidak cocok dengan checksum registry; tidak ada permintaan Google yang dikirim.',
            default => 'Berkas PDF sumber tidak dapat diverifikasi; tidak ada permintaan Google yang dikirim.',
        };
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
     * Token non-secret per generasi impor. Acak dan unik per baris: dokumen
     * yang sama diimpor dua kali pun punya token berbeda, dan token tidak
     * mengandung kredensial, checksum konten, atau rahasia lain.
     */
    private function newImportIdentity(): string
    {
        return 'g'.bin2hex(random_bytes(24));
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
            'remote_write_evidence' => GeminiRemoteWriteEvidence::NotStarted->value,
            'import_identity' => $this->newImportIdentity(),
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
     * Transisi hanya berlaku pada state dan generation yang dibaca job;
     * update nol baris berarti kepemilikan hilang dan job harus berhenti.
     * Nilai hasil tulis disalin ke model tanpa refresh(): refresh() bisa
     * menyerap generation yang lebih baru dan membuat langkah dari job basi
     * menulis atas nama pemilik generation baru.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function advance(KnowledgeDocumentGeminiIndex $index, GeminiDocumentIndexState $from, array $attributes): bool
    {
        $updatedAt = now();
        $updated = KnowledgeDocumentGeminiIndex::query()
            ->whereKey($index->id)
            ->where('state', $from->value)
            ->where('generation', $index->generation)
            ->update($attributes + ['updated_at' => $updatedAt]);

        if ($updated === 1) {
            $index->forceFill($attributes + ['updated_at' => $updatedAt])->syncOriginal();
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

    /**
     * AiException polos dari gateway aman berarti kontrak respons dilanggar
     * (payload tidak dapat divalidasi); subkelas failoverable seperti
     * rate-limit atau overload justru sementara dan tetap boleh diulang.
     */
    private function isContractViolation(Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AiException && ! $cause instanceof FailoverableException) {
                return true;
            }
        }

        return false;
    }
}
