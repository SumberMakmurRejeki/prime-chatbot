<?php

namespace App\Services\AI\Gateway;

use App\Services\AI\ProbeTransportPolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Gemini\GeminiStoreGateway;
use Laravel\Ai\Providers\Provider;

/**
 * Gateway store Gemini dengan batas transport PRIME.
 *
 * Operasi impor PRIME memakai importFileOperation() lalu getImportOperation():
 * identitas operasi dikembalikan apa adanya tanpa menunggu indeksasi, dan
 * statusnya diperiksa lewat permintaan GET terpisah yang juga dibatasi
 * transport. formatMetadata() dipertahankan supaya metadata string seperti
 * `"2.0"` tetap stringValue bila jalur SDK addFile() tetap dipakai.
 */
final class SecureGeminiStoreGateway extends GeminiStoreGateway
{
    use HardensOutboundTransport;

    public const DOCUMENT_CODE_KEY = 'document_code';

    public const VERSION_KEY = 'version';

    public function __construct(ProbeTransportPolicy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Kirim permintaan impor tanpa polling dan tanpa retry.
     *
     * @param  array{document_code: string, version: string}  $metadata
     * @return array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}
     */
    public function importFileOperation(StoreProvider $provider, string $storeId, string $fileId, array $metadata): array
    {
        if (! is_string($metadata[self::DOCUMENT_CODE_KEY] ?? null) || ! is_string($metadata[self::VERSION_KEY] ?? null)) {
            throw new AiException('Gemini File Search metadata requires string document_code and version values.');
        }

        $store = $this->normalizeStoreId($storeId);

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)
            ->post($this->baseUrl($provider)."/{$store}:importFile", [
                'fileName' => $this->normalizeFileId($fileId),
                'customMetadata' => [
                    ['key' => self::DOCUMENT_CODE_KEY, 'stringValue' => $metadata[self::DOCUMENT_CODE_KEY]],
                    ['key' => self::VERSION_KEY, 'stringValue' => $metadata[self::VERSION_KEY]],
                ],
            ])->throw());

        $operation = $this->parseOperation($response);

        $operationStore = $this->storeScope($operation['name']);

        if (! $this->isOperationName($operation['name']) || ($operationStore !== null && $operationStore !== $store)) {
            throw new AiException('Gemini returned an invalid File Search operation identity.');
        }

        return $this->assertImportOperation($this->assertDocumentStore($operation, $store));
    }

    /**
     * Baca status operasi impor sekali saja; tidak ada polling di sini.
     *
     * @return array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}
     */
    public function getImportOperation(StoreProvider $provider, string $operationName): array
    {
        if (! $this->isOperationName($operationName)) {
            throw new AiException('Gemini File Search operation identifier is invalid.');
        }

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)
            ->get($this->baseUrl($provider).'/'.$operationName)->throw());

        $operation = $this->parseOperation($response);

        if ($operation['name'] !== $operationName) {
            throw new AiException('Gemini returned an unrelated File Search operation.');
        }

        return $this->assertImportOperation($this->assertDocumentStore($operation, $this->storeScope($operationName)));
    }

    /**
     * Baca satu store yang sudah ada; tidak ada operasi tulis di sini.
     *
     * Status HTTP dikembalikan apa adanya agar lapisan operasi dapat
     * mengklasifikasikan penolakan akses, store hilang, dan kegagalan
     * sementara tanpa membocorkan isi respons provider. Respons sukses
     * wajib memuat identitas store yang diminta; identitas lain ditolak
     * sebagai respons tidak valid.
     *
     * @return array{status: int, store_name: ?string}
     */
    public function inspectStore(StoreProvider $provider, string $storeId): array
    {
        $store = $this->normalizeStoreId($storeId);

        $response = $this->client($provider)->get($this->baseUrl($provider)."/{$store}");

        return [
            'status' => $response->status(),
            'store_name' => $response->successful() ? $this->parseStoreIdentity($response, $store) : null,
        ];
    }

    /**
     * Metadata bisnis PRIME selalu string: `"2.0"` tidak boleh menjadi 2.0.
     */
    protected function formatMetadata(array $metadata): array
    {
        $formatted = [];

        foreach ($metadata as $key => $value) {
            $formatted[] = is_array($value)
                ? ['key' => $key, 'stringListValue' => ['values' => array_values($value)]]
                : ['key' => $key, 'stringValue' => (string) $value];
        }

        return $formatted;
    }

    protected function client(Provider $provider): PendingRequest
    {
        return $this->harden(parent::client($provider), $provider);
    }

    /**
     * @return array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}
     */
    private function parseOperation(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload) || ! is_string($payload['name'] ?? null) || $payload['name'] === ''
            || (isset($payload['done']) && ! is_bool($payload['done']))) {
            throw new AiException('Gemini returned an invalid File Search operation response.');
        }

        $documentName = $payload['response']['documentName'] ?? null;

        if ($documentName !== null
            && (! is_string($documentName) || preg_match('#^fileSearchStores/[A-Za-z0-9._-]+/documents/[A-Za-z0-9._-]+$#', $documentName) !== 1)) {
            throw new AiException('Gemini returned an invalid File Search document identity.');
        }

        $error = $payload['error'] ?? null;

        if ($error !== null && ! is_array($error)) {
            throw new AiException('Gemini returned an invalid File Search operation error.');
        }

        return [
            'name' => $payload['name'],
            'done' => $payload['done'] ?? false,
            'document_name' => $documentName,
            'error' => $error,
        ];
    }

    private function parseStoreIdentity(Response $response, string $expectedStore): string
    {
        $payload = $response->json();

        if (! is_array($payload) || ! is_string($payload['name'] ?? null)) {
            throw new AiException('Gemini Error: [invalid_response] File Search store response is malformed.');
        }

        // Identitas store hanya sah bila resource name persis store yang
        // diminta; metadata atau field lain tidak pernah jadi bukti.
        if ($payload['name'] !== $expectedStore) {
            throw new AiException('Gemini Error: [invalid_response] Gemini returned an unrelated File Search store identity.');
        }

        return $payload['name'];
    }

    /**
     * @param  array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}  $operation
     * @return array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}
     */
    private function assertImportOperation(array $operation): array
    {
        if ($operation['error'] === null && $operation['done'] && $operation['document_name'] === null) {
            throw new AiException('Gemini Error: [invalid_response] File import completed without a document name.');
        }

        return $operation;
    }

    /**
     * Dokumen selesai hanya diterima bila store-nya sesuai konteks operasi.
     *
     * Konteks yang dipercaya adalah store yang diminta saat impor atau scope
     * store pada nama operasi; metadata provider tidak pernah jadi bukti.
     *
     * @param  array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}  $operation
     * @return array{name: string, done: bool, document_name: ?string, error: ?array<string, mixed>}
     */
    private function assertDocumentStore(array $operation, ?string $expectedStore): array
    {
        if ($operation['error'] === null && $operation['done'] && $operation['document_name'] !== null) {
            if ($expectedStore === null || ! str_starts_with($operation['document_name'], $expectedStore.'/documents/')) {
                throw new AiException('Gemini Error: [invalid_response] File Search document belongs to an unverified store.');
            }
        }

        return $operation;
    }

    private function isOperationName(string $operationName): bool
    {
        return preg_match('#^(?:fileSearchStores/[A-Za-z0-9._-]+/)?operations/[A-Za-z0-9._~-]+$#', $operationName) === 1;
    }

    /**
     * Ambil `fileSearchStores/{store}` dari nama operasi; null bila tanpa scope.
     */
    private function storeScope(string $operationName): ?string
    {
        return preg_match('#^(fileSearchStores/[A-Za-z0-9._-]+)/operations/[A-Za-z0-9._~-]+$#', $operationName, $matches) === 1
            ? $matches[1]
            : null;
    }
}
