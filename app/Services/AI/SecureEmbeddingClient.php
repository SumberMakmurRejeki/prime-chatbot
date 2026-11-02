<?php

namespace App\Services\AI;

use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\Gateway\SecureOpenAiCompatibleGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Laravel\Ai\AiManager;
use RuntimeException;
use Throwable;

/**
 * Satu jalur pemanggilan embedding provider yang diperkeras (DNS pinning,
 * TLS, tanpa redirect/retry) untuk ingestion dan retrieval.
 *
 * Setiap vektor yang kembali wajib berjumlah sesuai input dan muat dalam
 * dimensi ruang vektor yang disetujui; respons yang menyimpang gagal tertutup
 * sebelum ada penulisan ke vector store.
 */
final class SecureEmbeddingClient
{
    private const BATCH_SIZE = 32;

    public function __construct(
        private readonly Application $app,
        private readonly AiEndpointPinner $pinner,
    ) {}

    /**
     * @param  list<string>  $texts
     * @return list<list<int|float>>
     */
    public function embed(AiProviderConfigurationRevision $revision, array $texts): array
    {
        $credential = null;
        $provider = null;
        $manager = null;
        $configuration = null;

        try {
            $policy = new ProbeTransportPolicy($this->pinner, 8 * 1024 * 1024);
            $policy->validateBaseUrl($revision->base_url);
            $credential = $revision->credential;
            $configuration = [
                'driver' => $revision->driver,
                'key' => $credential,
                'url' => $revision->base_url,
                'models' => ['text' => ['default' => EmbeddingRevisionResolver::MODEL], 'embeddings' => ['default' => EmbeddingRevisionResolver::MODEL]],
            ];
            $manager = new AiManager($this->app);
            $provider = $manager->build($configuration);
            $events = new Dispatcher($this->app);
            $gateway = new SecureOpenAiCompatibleGateway($events, $policy);
            $provider->useEmbeddingGateway($gateway);
            $result = [];

            foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
                $response = $gateway->generateEmbeddings(
                    $provider,
                    EmbeddingRevisionResolver::MODEL,
                    $batch,
                    EmbeddingRevisionResolver::DIMENSION,
                    ProbeTransportPolicy::TOTAL_TIMEOUT_SECONDS,
                );

                if (count($response->embeddings) !== count($batch)) {
                    throw new RuntimeException('Embedding provider returned an invalid response.');
                }

                foreach ($response->embeddings as $vector) {
                    if (! is_array($vector) || count($vector) !== EmbeddingRevisionResolver::DIMENSION) {
                        throw new RuntimeException('Embedding provider returned an invalid response.');
                    }

                    foreach ($vector as $value) {
                        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                            throw new RuntimeException('Embedding provider returned an invalid response.');
                        }
                    }

                    $result[] = $vector;
                }
            }

            return $result;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'Embedding provider returned an invalid response.') {
                throw $exception;
            }

            throw new RuntimeException('Embedding operation failed.', 0, $exception);
        } finally {
            unset($credential, $configuration, $provider, $manager, $revision);
        }
    }
}
