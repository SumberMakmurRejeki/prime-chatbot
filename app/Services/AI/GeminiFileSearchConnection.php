<?php

namespace App\Services\AI;

use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\Gateway\SecureGeminiFileGateway;
use App\Services\AI\Gateway\SecureGeminiStoreGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use RuntimeException;
use Throwable;

/**
 * Satu sumber koneksi Gemini File Search dari revisi chat provider aktif PRIME.
 *
 * Setiap operasi File Search (unggah PDF, import store, kueri tool) wajib
 * memakai instance provider yang dibangun di sini, sehingga kredensial selalu
 * berasal dari revisi aktif dan tidak pernah jatuh ke key default framework.
 * Revisi yang tidak eligible, bukan Gemini, atau tanpa kredensial gagal
 * tertutup tanpa fallback ke provider atau environment lain.
 *
 * Provider yang dikembalikan sudah memakai gateway File/Store PRIME yang
 * membatasi tujuan keluar (HTTPS, host publik, pin DNS, tanpa redirect/proxy,
 * timeout, dan batas ukuran respons) sebelum ada operasi jaringan apa pun.
 */
final class GeminiFileSearchConnection
{
    public const OFFICIAL_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly ChatProviderResolver $providers,
        private readonly Application $app,
        private readonly AiEndpointPinner $pinner,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Bangun instance provider Gemini SDK dari revisi chat aktif.
     */
    public function resolve(): GeminiFileSearchProvider
    {
        return $this->resolveWithCredential()['provider'];
    }

    /**
     * Satu resolusi yang mengembalikan provider DAN identitas revisi
     * kredensial yang menyediakannya.
     *
     * Provenance harus diambil dari objek ini, bukan dari pemanggilan resolve()
     * terpisah: revisi chat bisa berpindah di antara dua resolusi, sehingga
     * membaca ulang sesudah permintaan tidak membuktikan kredensial mana yang
     * benar-benar dipakai. Nilai yang dikembalikan hanya identitas non-secret.
     *
     * @return array{provider: GeminiFileSearchProvider, configuration_id: int, revision_id: int}
     */
    public function resolveWithCredential(): array
    {
        $revision = $this->providers->resolve();

        if (Lab::tryFrom($revision->driver) !== Lab::Gemini) {
            throw new RuntimeException('The active chat provider does not support Gemini File Search.');
        }

        // Satu policy per resolusi: status penolakan transport tidak menyeberang
        // ke operasi lain, dan endpoint resmi dipakai bila revisi tanpa base_url.
        $policy = new ProbeTransportPolicy($this->pinner);
        $baseUrl = filled($revision->base_url) ? (string) $revision->base_url : self::OFFICIAL_BASE_URL;

        try {
            // Custom endpoint divalidasi kebijakan PRIME sebelum ada I/O. Tidak
            // ada fallback diam-diam ke endpoint lain saat validasi gagal.
            $policy->validateBaseUrl($baseUrl);
        } catch (UnsafeEndpointException $exception) {
            throw new RuntimeException('The active Gemini provider endpoint is not allowed.', 0, $exception);
        }

        $provider = $this->provider($revision);
        $provider->useFileGateway(new SecureGeminiFileGateway($policy));
        $provider->useStoreGateway(new SecureGeminiStoreGateway($policy));

        return [
            'provider' => $provider,
            'configuration_id' => (int) $revision->configuration_id,
            'revision_id' => (int) $revision->id,
        ];
    }

    private function provider(AiProviderConfigurationRevision $revision): GeminiFileSearchProvider
    {
        try {
            // Cast `credential` mendekripsi hanya di sini; nilainya tidak pernah
            // disalin ke config, cache, session, atau payload antrean.
            $credential = $revision->credential;

            if (! is_string($credential) || trim($credential) === '') {
                throw new RuntimeException('The active Gemini provider credential is unavailable.');
            }

            $configuration = [
                'driver' => Lab::Gemini->value,
                'key' => $credential,
                'models' => ['text' => ['default' => $revision->model_id]],
            ];

            if (filled($revision->base_url)) {
                $configuration['url'] = $revision->base_url;
            }

            // Konstruksi meniru AiManager::createGeminiDriver() pada laravel/ai
            // v1.2.0 (GeminiGateway + config + dispatcher) dengan kelas provider
            // PRIME, agar gateway File/Store aman di bawah tetap terpasang.
            $provider = new GeminiFileSearchProvider(
                new GeminiGateway($this->events),
                [...$configuration, 'name' => 'gemini'],
                $this->events,
            );

            return $provider;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('The active Gemini provider configuration is unavailable.', 0, $exception);
        } finally {
            // Referensi operasional dilepas eksplisit mengikuti konvensi
            // SecureEmbeddingClient; tidak ada klaim zeroisasi memori.
            unset($credential, $configuration, $revision);
        }
    }
}
