<?php

namespace App\Services\AI;

use App\Services\AI\Gateway\SecureGeminiStoreGateway;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Providers\GeminiProvider;
use RuntimeException;
use Throwable;

/**
 * Operasi File Search minimum: unggah satu PDF, mulai impor, dan baca status.
 *
 * Tidak ada polling otomatis, retry permintaan non-idempoten, pembuatan atau
 * penghapusan store, dan orkestrasi antrean. Setiap kegagalan dilempar dengan
 * pesan generik yang aman untuk UI; detail provider tidak pernah dibocorkan.
 */
final class GeminiFileSearchOperations
{
    public const MAX_PDF_BYTES = 100 * 1024 * 1024;

    public function __construct(private readonly GeminiFileSearchConnection $connection) {}

    /**
     * Unggah satu PDF lokal dan kembalikan identitas berkas provider.
     * Hasil unggah yang tidak pasti tidak pernah diulang otomatis.
     */
    public function uploadPdf(string $localPath): string
    {
        $this->assertValidPdf($localPath);

        try {
            $uploaded = $this->connection->resolve()->putFile(
                (new LocalDocument($localPath, 'application/pdf'))->as(basename($localPath)),
            );
        } catch (ProviderConnectionException) {
            // SDK memetakan koneksi gagal/timeout menjadi exception ini. Hasil
            // unggah di sisi Google tidak diketahui, jadi pemanggil tidak boleh
            // mengulang buta.
            throw new RuntimeException('Gemini PDF upload outcome is unknown and was not retried.');
        } catch (Throwable $exception) {
            throw new RuntimeException('Gemini PDF upload failed.', 0, $exception);
        }

        if (preg_match('#^files/[A-Za-z0-9._-]+$#', $uploaded->id) !== 1) {
            throw new RuntimeException('Gemini File Search returned an unknown uploaded file identity.');
        }

        return $uploaded->id;
    }

    /**
     * Mulai impor berkas ke store yang sudah ada. Status yang dikembalikan
     * berasal dari respons impor; operasi yang belum selesai diperiksa lagi
     * lewat inspectIndexing(). Provenance berasal dari provider yang benar-benar
     * mengirim request, bukan dari resolusi terpisah yang berpotensi berganti.
     */
    public function importFile(string $storeId, string $fileId, GeminiDocumentMetadata $metadata): GeminiIndexingReport
    {
        if (preg_match('#^(fileSearchStores/)?[A-Za-z0-9._-]+$#', $storeId) !== 1
            || preg_match('#^(files/)?[A-Za-z0-9._-]+$#', $fileId) !== 1) {
            throw new RuntimeException('Gemini File Search resource identity is invalid.');
        }

        try {
            $connection = $this->connection->resolveWithCredential();
            $provider = $connection['provider'];
            $operation = $this->secureStoreGateway($provider)
                ->importFileOperation($provider, $storeId, $fileId, $metadata->toImportMetadata());
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('Gemini File Search import request failed.', 0, $exception);
        }

        return $this->report($operation, $connection['configuration_id'], $connection['revision_id']);
    }

    /**
     * Baca status operasi indeksasi satu kali tanpa polling.
     */
    public function inspectIndexing(string $operationName): GeminiIndexingReport
    {
        try {
            $provider = $this->connection->resolve();
            $operation = $this->secureStoreGateway($provider)->getImportOperation($provider, $operationName);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('Gemini File Search indexing status could not be read.', 0, $exception);
        }

        return $this->report($operation);
    }

    /** Store tujuan terkonfigurasi dalam bentuk resource kanonis tanpa verifikasi jaringan. */
    public function configuredStoreIdentity(): ?string
    {
        $configuredStore = config('gemini_file_search.indexing.store');

        if (! is_string($configuredStore) || trim($configuredStore) === ''
            || preg_match('#^(fileSearchStores/)?[A-Za-z0-9._-]+$#', $configuredStore) !== 1) {
            return null;
        }

        return str_starts_with($configuredStore, 'fileSearchStores/')
            ? $configuredStore
            : 'fileSearchStores/'.$configuredStore;
    }

    /**
     * Periksa akses ke store terkonfigurasi satu kali tanpa mengubah state.
     */
    public function checkStoreReadiness(): GeminiStoreReadiness
    {
        $configuredStore = $this->configuredStoreIdentity();

        if ($configuredStore === null) {
            return new GeminiStoreReadiness(GeminiStoreReadinessState::NotConfigured, 'store_not_configured');
        }

        try {
            $provider = $this->connection->resolve();
        } catch (RuntimeException $exception) {
            // Revisi chat aktif tidak eligible atau endpoint tidak aman; File
            // Search memang belum terkonfigurasi bila penyebabnya kredensial.
            if ($exception->getPrevious() instanceof UnsafeEndpointException) {
                return new GeminiStoreReadiness(GeminiStoreReadinessState::Unavailable, 'transport_rejected');
            }

            return new GeminiStoreReadiness(GeminiStoreReadinessState::NotConfigured, 'credential_not_configured');
        }

        try {
            $inspection = $this->secureStoreGateway($provider)->inspectStore($provider, $configuredStore);
        } catch (AiException) {
            return new GeminiStoreReadiness(GeminiStoreReadinessState::InvalidResponse, 'provider_response_invalid');
        } catch (ConnectionException) {
            return new GeminiStoreReadiness(GeminiStoreReadinessState::Unavailable, 'connection_failed');
        } catch (UnsafeEndpointException) {
            return new GeminiStoreReadiness(GeminiStoreReadinessState::Unavailable, 'transport_rejected');
        } catch (Throwable) {
            return new GeminiStoreReadiness(GeminiStoreReadinessState::Unavailable, 'request_failed');
        }

        return $this->readinessFromInspection($inspection);
    }

    /**
     * @param  array{status: int, store_name: ?string}  $inspection
     */
    private function readinessFromInspection(array $inspection): GeminiStoreReadiness
    {
        // store_name hanya terisi bila HTTP sukses DAN identitas yang
        // dikembalikan Gemini persis store yang diminta (validasi gateway).
        return match (true) {
            $inspection['store_name'] !== null => new GeminiStoreReadiness(
                GeminiStoreReadinessState::Accessible,
                'store_accessible',
                $inspection['store_name'],
            ),
            $inspection['status'] === 401, $inspection['status'] === 403 => new GeminiStoreReadiness(GeminiStoreReadinessState::AccessDenied, 'access_denied'),
            $inspection['status'] === 404 => new GeminiStoreReadiness(GeminiStoreReadinessState::MissingStore, 'store_not_found'),
            default => new GeminiStoreReadiness(GeminiStoreReadinessState::Unavailable, 'provider_unavailable'),
        };
    }

    private function secureStoreGateway(GeminiProvider $provider): SecureGeminiStoreGateway
    {
        $gateway = $provider->storeGateway();

        if (! $gateway instanceof SecureGeminiStoreGateway) {
            // Terjadi hanya bila provider tidak dibangun lewat
            // GeminiFileSearchConnection::resolve() yang memasang gateway aman.
            throw new RuntimeException('Gemini File Search secure gateway is unavailable.');
        }

        return $gateway;
    }

    /**
     * @param  array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}  $operation
     */
    private function report(array $operation, ?int $configurationId = null, ?int $revisionId = null): GeminiIndexingReport
    {
        return new GeminiIndexingReport(
            state: GeminiIndexingState::fromOperation($operation),
            operationName: $operation['name'],
            documentName: $operation['document_name'],
            credentialConfigurationId: $configurationId,
            credentialRevisionId: $revisionId,
        );
    }

    private function assertValidPdf(string $localPath): void
    {
        $size = is_file($localPath) && is_readable($localPath) ? filesize($localPath) : false;
        $mime = $size !== false && $size > 0 ? (new \finfo(FILEINFO_MIME_TYPE))->file($localPath) : false;

        if ($size === false || $size < 1 || $size > self::MAX_PDF_BYTES
            || strtolower(pathinfo($localPath, PATHINFO_EXTENSION)) !== 'pdf'
            || $mime !== 'application/pdf') {
            throw new RuntimeException('Gemini upload requires a readable local PDF within the allowed size limit.');
        }
    }
}
