<?php

namespace App\Services\AI;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\KnowledgeDocument;
use App\Services\AI\DocumentIngestion\DocumentMetadata;
use App\Services\AI\DocumentIngestion\PdfIngestionException;
use App\Services\AI\DocumentIngestion\PdfIngestionService;
use App\Services\AI\VectorStore\VectorStore;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Registry Knowledge Base Admin (AI-4KB1).
 *
 * MySQL adalah sumber kebenaran metadata/lifecycle; storage privat menyimpan
 * PDF asli; Qdrant hanya indeks yang dapat dibangun ulang. Titik Qdrant disimpan
 * non-aktif (retrieval_active=false) hingga dokumen READY dan dipublikasikan.
 */
final class KnowledgeDocumentService
{
    public function __construct(
        private readonly PdfIngestionService $ingestion,
        private readonly DocumentEmbeddingIndexingService $indexing,
        private readonly EmbeddingRevisionResolver $revisions,
        private readonly VectorStore $vectorStore,
    ) {}

    /**
     * Simpan PDF asli secara privat lalu catat metadata registry.
     *
     * Berkas ditulis sebelum transaksi DB; bila registry gagal, berkas dihapus
     * supaya storage tidak menyimpan orphan tanpa metadata.
     *
     * @throws ValidationException konflik duplikat
     */
    public function store(UploadedFile $file, string $documentCode, string $title, string $version, int $uploaderId): KnowledgeDocument
    {
        $disk = Storage::disk('local');
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            throw new RuntimeException('Checksum dokumen tidak dapat dihitung.');
        }

        $signature = file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($signature !== '%PDF-') {
            throw ValidationException::withMessages(['document' => 'Berkas harus memiliki signature PDF yang valid.']);
        }

        if (KnowledgeDocument::query()->where('checksum', $checksum)->exists()) {
            throw ValidationException::withMessages(['document' => 'Konten dokumen ini sudah terdaftar sebelumnya.']);
        }

        if (KnowledgeDocument::query()->where('document_code', $documentCode)->where('version', $version)->exists()) {
            throw ValidationException::withMessages(['version' => 'Kombinasi kode dan versi dokumen sudah terdaftar.']);
        }

        // Nama berkas di storage dihasilkan sendiri; nama asli hanya tersimpan
        // sebagai provenance di MySQL agar tidak menjadi vektor path/traversal.
        $path = sprintf('knowledge-documents/%s/%s/%s.pdf', $documentCode, $version, (string) Str::uuid());

        if (! $disk->put($path, file_get_contents($file->getRealPath()))) {
            throw new RuntimeException('Dokumen tidak dapat disimpan ke storage privat.');
        }

        try {
            return DB::transaction(function () use ($documentCode, $title, $version, $checksum, $path, $file, $uploaderId): KnowledgeDocument {
                return KnowledgeDocument::query()->create([
                    'document_code' => $documentCode,
                    'title' => $title,
                    'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'version' => $version,
                    'checksum' => $checksum,
                    'storage_path' => $path,
                    'file_size' => (int) $file->getSize(),
                    'uploaded_by' => $uploaderId,
                ]);
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            if (KnowledgeDocument::query()->where('checksum', $checksum)->exists()) {
                throw ValidationException::withMessages(['document' => 'Konten dokumen ini sudah terdaftar sebelumnya.']);
            }
            if (KnowledgeDocument::query()->where('document_code', $documentCode)->where('version', $version)->exists()) {
                throw ValidationException::withMessages(['version' => 'Kombinasi kode dan versi dokumen sudah terdaftar.']);
            }
            throw $exception;
        }
    }

    /**
     * Pipeline ungah → proses → READY. Idempoten: dokumen READY dilewati.
     *
     * Penulisan Qdrant memakai retrieval_active=false sehingga dokumen yang
     * gagal/belum dipublikasikan tidak pernah dapat diambil Chat RAG.
     */
    public function process(int $documentId): void
    {
        $document = KnowledgeDocument::query()->find($documentId);
        if ($document === null || $document->status === 'ready') {
            return;
        }

        $document->forceFill(['status' => 'processing', 'safe_error_code' => null, 'safe_error_message' => null])->save();

        try {
            if (! Storage::disk('local')->exists($document->storage_path)) {
                throw new RuntimeException('Original PDF tidak ditemukan di storage privat.');
            }

            $chunks = $this->ingestion->ingest(
                Storage::disk('local')->path($document->storage_path),
                new DocumentMetadata($document->document_code, $document->version),
            );

            if ($chunks === []) {
                throw new RuntimeException('Tidak ada chunk yang dihasilkan dari ekstraksi.');
            }

            // Provenance wajib cocok: checksum hasil ekstraksi harus sama dengan
            // yang didaftarkan saat ungah, bukti berkas tidak berubah.
            if ($chunks[0]->sourceSha256 !== $document->checksum) {
                throw new RuntimeException('Checksum berkas berubah sejak diunggah; proses dibatalkan.');
            }

            $space = $this->indexing->index($chunks, false);

            $maxPage = 0;
            foreach ($chunks as $chunk) {
                $maxPage = max($maxPage, $chunk->pageEnd);
            }

            KnowledgeDocument::query()->whereKey($documentId)->where('status', 'processing')->update([
                'status' => 'ready',
                'page_count' => $maxPage,
                'chunk_count' => count($chunks),
                'vector_space_identity' => $space->identity,
                'pipeline_fingerprint' => $chunks[0]->pipelineFingerprint,
                'processed_at' => now(),
                'safe_error_code' => null,
                'safe_error_message' => null,
                'updated_at' => now(),
            ]);
        } catch (PdfIngestionException $exception) {
            $this->markFailed($documentId, 'ingestion_failed', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Knowledge document processing failed.', ['document_id' => $documentId, 'exception' => $exception::class]);
            $this->markFailed($documentId, 'processing_failed', 'Pemrosesan dokumen gagal di tengah proses.');
        }
    }

    /**
     * Gerbang publikasi eksplisit dengan Owner `manage_ai`:
     * hanya dokumen READY yang boleh menjadi eligible retrieval.
     *
     * Semua revisi ber-kode sama dikunci (row-lock, urutan id tetap) sebelum
     * mutasi apa pun, sehingga dua publikasi yang bersamaan tetap menyisakan
     * satu revisi terpublikasi. Revisi yang digantikan hanya kehilangan flag
     * is_published; baris, timestamp, dan publisher-nya tetap tersimpan sebagai
     * bukti historis dan tidak dihapus.
     *
     * Titik Qdrant revisi baru diaktifkan di dalam transaksi yang sama; bila
     * aktivasi gagal, transaksi rollback sehingga MySQL tetap tidak berubah
     * (fail-closed) dan publish dapat dicoba ulang.
     *
     * @throws DomainException bila state tidak valid atau ruang vektor berganti
     */
    public function publish(int $documentId, int $publisherId): KnowledgeDocument
    {
        return DB::transaction(function () use ($documentId, $publisherId): KnowledgeDocument {
            // document_code tidak pernah berubah setelah unggah, sehingga kode
            // dapat dibaca tanpa lock untuk menentukan cakupan kunci. Urutan id
            // tetap memakai urutan kunci yang sama untuk semua publisher.
            $target = KnowledgeDocument::query()->findOrFail($documentId);
            $lockedDocuments = KnowledgeDocument::query()
                ->where('document_code', $target->document_code)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $document = $lockedDocuments->firstWhere('id', $target->id);

            if ($document === null || $document->status !== 'ready' || $document->is_published) {
                throw new DomainException('Hanya dokumen READY yang belum dipublikasikan dapat dipublikasikan.');
            }

            if ($document->vector_space_identity === null) {
                throw new DomainException('Dokumen belum memiliki provenance ruang vektor yang valid.');
            }

            $revision = $this->revisions->resolve();
            $space = $this->revisions->space($revision);
            if ($document->vector_space_identity !== $space->identity) {
                // Ruangembedding aktif berganti sejak index dengan dokumen ini;
                // titik milik dokumen tidak boleh diaktifkan di space baru.
                throw new DomainException('Dokumen tereindeks pada ruang vektor yang berbeda; proses ulang dokumen.');
            }

            $this->vectorStore->setRetrievalActive($space, $document->document_code, $document->version, true);

            // Kunci di atas membuat publikasi berkode sama saling menunggu,
            // sehingga revisi lama tidak mungkin tetap terpublikasi bersamaan.
            // Titik Qdrant revisi lama sengaja tidak diubah; MySQL yang
            // mengecualikannya dari evidence meski penandanya masih aktif.
            KnowledgeDocument::query()
                ->where('document_code', $document->document_code)
                ->whereKeyNot($document->getKey())
                ->where('is_published', true)
                ->update(['is_published' => false]);

            // Provenance revisi yang pernah dipublikasikan dipertahankan: hanya
            // flag current yang berubah ketika revisi lama diaktifkan kembali.
            $document->forceFill($document->published_at === null
                ? ['is_published' => true, 'published_at' => now(), 'published_by' => $publisherId]
                : ['is_published' => true])->save();

            return $document;
        });
    }

    /**
     * Ulangi proses dokumen GAGAL: kembali ke status uploaded lalu antrikan ulang;
     * retry tidak membuat versi baru (satu row registry dipakai ulang).
     *
     * @throws DomainException bila dokumen tidak berstatus FAILED
     */
    public function retry(int $documentId): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()->findOrFail($documentId);
        if ($document->status !== 'failed') {
            throw new DomainException('Hanya dokumen GAGAL yang dapat diproses ulang.');
        }

        $document->forceFill(['status' => 'uploaded', 'safe_error_code' => null, 'safe_error_message' => null])->save();
        ProcessKnowledgeDocument::dispatch($document->id)->afterCommit();

        return $document;
    }

    private function markFailed(int $documentId, string $code, string $message): void
    {
        KnowledgeDocument::query()
            ->whereKey($documentId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'safe_error_code' => $code,
                'safe_error_message' => mb_substr($message, 0, 255),
                'updated_at' => now(),
            ]);
    }
}
