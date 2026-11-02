<?php

namespace App\Services\AI;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Model discovery OpenAI-compatible V1: GET {base_url}/models.
 *
 * Setiap permintaan memakai ProbeTransportPolicy yang sama dengan Test
 * Connection (HTTPS wajib, host publik, pinning DNS, tanpa redirect/proxy,
 * verifikasi TLS, timeout, dan batas ukuran respons). Kelas ini tidak membuat
 * jalur jaringan kedua dan tidak pernah menyimpan, mencatat, atau mengembalikan
 * credential maupun isi respons provider.
 *
 * Sebuah ID model hanya berarti provider mengiklankan ID tersebut; hasil
 * discovery tidak pernah membuktikan kemampuan chat/embedding dan tidak pernah
 * mengaktifkan atau menandai lulus sebuah revisi.
 */
final class ModelDiscoveryService
{
    public const MAX_MODELS = 200;

    public const MAX_MODEL_ID_LENGTH = 255;

    public function __construct(private readonly AiEndpointPinner $pinner) {}

    public function discover(string $baseUrl, ?string $credential): ModelDiscoveryResult
    {
        $policy = new ProbeTransportPolicy($this->pinner);

        try {
            // Validasi URL sebelum ada I/O apa pun.
            $policy->validateBaseUrl($baseUrl);
        } catch (UnsafeEndpointException $exception) {
            return ModelDiscoveryResult::failure($this->messageFor($exception->category()));
        }

        $client = Http::acceptJson();

        if (filled($credential)) {
            // Credential hanya hidup selama operasi keluar ini.
            $client = $client->withToken($credential);
        }

        try {
            $response = $policy->apply($client, $baseUrl)->get(rtrim($baseUrl, '/').'/models');
        } catch (UnsafeEndpointException $exception) {
            return ModelDiscoveryResult::failure($this->messageFor($exception->category()));
        } catch (ConnectionException $exception) {
            return ModelDiscoveryResult::failure($this->isTimeout($exception) ? 'Permintaan terlalu lama.' : 'Provider tidak dapat dihubungi.');
        } catch (Throwable) {
            // Pesan provider mentah tidak pernah keluar dari batas discovery.
            return ModelDiscoveryResult::failure('Provider tidak dapat dihubungi.');
        }

        if ($policy->responseTooLarge()) {
            return ModelDiscoveryResult::failure('Respons daftar model tidak valid.');
        }

        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            return ModelDiscoveryResult::failure(match (true) {
                $status === 401 || $status === 403 => 'API Key ditolak.',
                $status === 404 => 'Daftar model tidak tersedia.',
                $status === 408 || $status === 504 => 'Permintaan terlalu lama.',
                default => 'Provider tidak dapat dihubungi.',
            });
        }

        return $this->parse($response->json());
    }

    /**
     * Bentuk kanonik: {"object":"list","data":[{"id":"..."}]}. Item string
     * polos juga diterima selama bentuknya sempit dan jelas.
     */
    private function parse(mixed $payload): ModelDiscoveryResult
    {
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return ModelDiscoveryResult::failure('Respons daftar model tidak valid.');
        }

        $models = [];

        foreach ($payload['data'] as $item) {
            $id = match (true) {
                is_string($item) => $item,
                is_array($item) && is_string($item['id'] ?? null) => $item['id'],
                default => null,
            };

            if ($id === null) {
                continue;
            }

            $id = trim($id);

            if ($id === '' || mb_strlen($id) > self::MAX_MODEL_ID_LENGTH) {
                continue;
            }

            $models[$id] = true;
        }

        // PHP memaksa kunci array berupa angka menjadi int, jadi setiap ID
        // dikembalikan lagi ke string agar kontrak response tetap terpenuhi.
        $models = array_map(strval(...), array_keys($models));
        sort($models, SORT_STRING);

        return ModelDiscoveryResult::success(array_slice($models, 0, self::MAX_MODELS));
    }

    /**
     * Kategori transport (SSRF, redirect, ukuran) dipetakan ke pesan Admin yang
     * singkat tanpa detail internal.
     */
    private function messageFor(ProviderTestCategory $category): string
    {
        return match ($category) {
            ProviderTestCategory::UnsafeBaseUrl => 'Base URL tidak aman.',
            ProviderTestCategory::Timeout => 'Permintaan terlalu lama.',
            ProviderTestCategory::MalformedResponse => 'Respons daftar model tidak valid.',
            default => 'Provider tidak dapat dihubungi.',
        };
    }

    /**
     * Timeout transport dikenali dari errno cURL pada rantai exception, bukan
     * dari teks pesan provider.
     */
    private function isTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException
                && ($current->getHandlerContext()['errno'] ?? null) === CURLE_OPERATION_TIMEDOUT) {
                return true;
            }
        }

        return false;
    }
}
