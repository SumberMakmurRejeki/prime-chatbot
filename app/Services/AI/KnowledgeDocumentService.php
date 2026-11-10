<?php

namespace App\Services\AI;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\KnowledgeDocument;
use App\Services\AI\DocumentIngestion\DocumentMetadata;
use App\Services\AI\DocumentIngestion\PdfIngestionException;
use App\Services\AI\DocumentIngestion\PdfIngestionService;
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
 * Registry Knowledge Base Admin.
 *
 * MySQL adalah sumber kebenaran metadata dan publikasi; storage privat
 * menyimpan PDF, sedangkan indeks Gemini menjadi bukti turunan yang harus
 * sudah terverifikasi sebelum revisi baru boleh dipublikasikan.
 */
final class KnowledgeDocumentService
{
    public function __construct(
        private readonly PdfIngestionService $ingestion,
        private readonly GeminiRetrievalEligibilityService $geminiEligibility,
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
     * Ingest PDF dan tandai registry `ready` setelah seluruh checksum tervalidasi.
     *
     * Status `ready` hanya menyatakan PDF registry telah diproses. Gemini
     * indexing adalah lifecycle terpisah dan tidak dimulai atau disimpulkan
     * oleh pemrosesan ini.
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

            // Provenance wajib cocok agar hasil ekstraksi tetap menunjuk ke
            // byte PDF yang didaftarkan saat unggah.
            if ($chunks[0]->sourceSha256 !== $document->checksum) {
                throw new RuntimeException('Checksum berkas berubah sejak diunggah; proses dibatalkan.');
            }

            $maxPage = 0;
            foreach ($chunks as $chunk) {
                $maxPage = max($maxPage, $chunk->pageEnd);
            }

            KnowledgeDocument::query()->whereKey($documentId)->where('status', 'processing')->update([
                'status' => 'ready',
                'page_count' => $maxPage,
                'chunk_count' => count($chunks),
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
     * Publikasi Admin eksplisit; bukti indeksasi Gemini dinilai dari state
     * persisten sebelum revisi saat ini disentuh.
     *
     * Kunci seluruh revisi berkode sama sebelum mutasi agar publikasi
     * bersamaan tetap menyisakan satu current revision. Semua verifikasi
     * berasal dari database/config; transaksi ini tidak menghubungi Gemini.
     * Revisi yang digantikan kehilangan flag current saja, histori publikasinya
     * tetap tersimpan.
     *
     * @throws DomainException bila state registry atau bukti indeks tidak valid
     */
    public function publish(int $documentId, int $publisherId): KnowledgeDocument
    {
        return DB::transaction(function () use ($documentId, $publisherId): KnowledgeDocument {
            // Kode tidak berubah setelah upload. Baca target tanpa lock untuk
            // menentukan cakupan; semua publisher kemudian mengunci revisi
            // dalam urutan id yang sama sebelum satu pun mutasi.
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

            if ($this->geminiEligibility->trustedIndexFor($document) === null) {
                throw new DomainException('Publikasi ditolak: indeks Gemini File Search dokumen ini belum terverifikasi.');
            }

            KnowledgeDocument::query()
                ->where('document_code', $document->document_code)
                ->whereKeyNot($document->getKey())
                ->where('is_published', true)
                ->update(['is_published' => false]);

            // Publikasi ulang revisi arsip mempertahankan aktor dan timestamp
            // pertama; hanya flag revisi current yang berpindah.
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
