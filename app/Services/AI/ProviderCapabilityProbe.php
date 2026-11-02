<?php

namespace App\Services\AI;

use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\Gateway\SecureGeminiGateway;
use App\Services\AI\Gateway\SecureOpenAiCompatibleGateway;
use App\Services\AI\Gateway\SecureOpenAiGateway;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

/**
 * Menjalankan tepat satu operasi kemampuan (chat atau embedding) pada revisi
 * yang diminta lewat provider SDK on-demand.
 *
 * Manager, provider, credential plaintext, respons, dan vektor hanya hidup
 * selama pemanggilan ini; pemanggil hanya menerima kategori aman atau dimensi
 * vektor. Tidak ada config AI global, .env, opsi HTTP global, singleton, atau
 * listener event aplikasi yang disentuh: event SDK dikirim ke dispatcher
 * operasi-lokal supaya prompt/respons tidak dapat tertahan listener mana pun.
 */
class ProviderCapabilityProbe
{
    private const CHAT_INSTRUCTIONS = 'You are running a short connectivity test. Answer briefly.';

    private const CHAT_PROMPT = 'Reply with OK.';

    private const EMBEDDING_INPUT = 'PRIME connection test';

    private const SUPPORTED_DRIVERS = [Lab::OpenAI, Lab::OpenAICompatible, Lab::Gemini, Lab::OpenRouter];

    public function __construct(
        private readonly Application $app,
        private readonly AiEndpointPinner $pinner,
    ) {}

    public function probe(AiProviderConfigurationRevision $revision, string $purpose): ProviderProbeResult
    {
        if (! in_array($purpose, ['chat', 'embedding'], true)) {
            return ProviderProbeResult::failure(ProviderTestCategory::UnsupportedConfiguration);
        }

        $driver = Lab::tryFrom($revision->driver);

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return ProviderProbeResult::failure(ProviderTestCategory::UnsupportedConfiguration);
        }

        $baseUrl = $revision->base_url;

        if ($driver === Lab::OpenAICompatible && blank($baseUrl)) {
            return ProviderProbeResult::failure(ProviderTestCategory::UnsupportedConfiguration);
        }

        $policy = new ProbeTransportPolicy($this->pinner);

        if (filled($baseUrl)) {
            try {
                $policy->validateBaseUrl($baseUrl);
            } catch (UnsafeEndpointException $exception) {
                return ProviderProbeResult::failure($exception->category());
            }
        }

        $manager = null;
        $provider = null;
        $credential = null;

        try {
            // Cast `credential` mendekripsi hanya di sini; nilainya tidak pernah
            // disalin ke config, cache, session, atau payload antrean.
            $credential = $revision->credential;
            $configuration = [
                'driver' => $driver->value,
                'key' => $credential,
                'models' => [
                    'text' => ['default' => $revision->model_id],
                    'embeddings' => ['default' => $revision->model_id],
                ],
            ];

            if (filled($baseUrl)) {
                $configuration['url'] = $baseUrl;
            }

            $events = new Dispatcher($this->app);
            $manager = new AiManager($this->app);
            $provider = $manager->build($configuration);
            $secureGateway = $this->gateway($driver, $policy, $events);
            $provider->useTextGateway($secureGateway);

            return $purpose === 'chat'
                ? $this->probeChat($provider, $revision)
                : $this->probeEmbedding($secureGateway, $provider, $revision, $driver);
        } catch (UnsafeEndpointException $exception) {
            return ProviderProbeResult::failure($exception->category());
        } catch (RateLimitedException) {
            return ProviderProbeResult::failure(ProviderTestCategory::RateLimited);
        } catch (InsufficientCreditsException) {
            return ProviderProbeResult::failure(ProviderTestCategory::QuotaOrCreditExhausted);
        } catch (ProviderOverloadedException) {
            return ProviderProbeResult::failure(ProviderTestCategory::ProviderUnavailable);
        } catch (ProviderConnectionException|ConnectionException $exception) {
            return ProviderProbeResult::failure($this->transportOutcome($policy)
                ?? ($this->isTimeout($exception) ? ProviderTestCategory::Timeout : ProviderTestCategory::ProviderUnavailable));
        } catch (RequestException $exception) {
            return ProviderProbeResult::failure($this->transportOutcome($policy) ?? $this->classifyFailure($exception));
        } catch (AiException|\TypeError) {
            // Parser/protokol SDK yang gagal dipetakan ke respons tidak valid.
            return ProviderProbeResult::failure($this->transportOutcome($policy) ?? ProviderTestCategory::MalformedResponse);
        } catch (Throwable) {
            return ProviderProbeResult::failure($this->transportOutcome($policy) ?? ProviderTestCategory::ProviderRequestRejected);
        } finally {
            // Referensi operasional dilepas eksplisit; tidak ada klaim zeroisasi memori.
            unset($provider, $manager, $configuration, $credential, $revision);
        }
    }

    /**
     * Penolakan yang tercatat transport (redirect/ukuran) mendahului
     * klasifikasi status HTTP generik.
     */
    private function transportOutcome(ProbeTransportPolicy $policy): ?ProviderTestCategory
    {
        if ($policy->redirectRejected()) {
            return ProviderTestCategory::RedirectRejected;
        }

        return $policy->responseTooLarge() ? ProviderTestCategory::MalformedResponse : null;
    }

    private function probeChat(Provider $provider, AiProviderConfigurationRevision $revision): ProviderProbeResult
    {
        $parameters = is_array($revision->generation_parameters) ? $revision->generation_parameters : [];
        $configuredLimit = isset($parameters['max_tokens']) ? (int) $parameters['max_tokens'] : 64;
        $temperature = isset($parameters['temperature']) ? (float) $parameters['temperature'] : null;

        $response = $provider->textGenerationLoop()->generate(
            $provider,
            $revision->model_id,
            self::CHAT_INSTRUCTIONS,
            [new UserMessage(self::CHAT_PROMPT)],
            [],
            null,
            new TextGenerationOptions(maxSteps: 1, maxTokens: max(1, min(64, $configuredLimit)), temperature: $temperature),
            ProbeTransportPolicy::TOTAL_TIMEOUT_SECONDS,
        );

        if (trim($response->text) === '' || $response->toolCalls->isNotEmpty() || $response->pendingApprovals->isNotEmpty()) {
            return ProviderProbeResult::failure(ProviderTestCategory::GenerationRejected);
        }

        foreach ($response->steps as $step) {
            if ($step->finishReason === FinishReason::ContentFilter
                || $step->finishReason === FinishReason::Error
                || $step->toolCalls !== []) {
                return ProviderProbeResult::failure(ProviderTestCategory::GenerationRejected);
            }
        }

        return ProviderProbeResult::success();
    }

    private function probeEmbedding(StepTextGateway&EmbeddingGateway $gateway, Provider $provider, AiProviderConfigurationRevision $revision, Lab $driver): ProviderProbeResult
    {
        // openai-compatible boleh memakai dimensi native model; driver resmi SDK
        // mensyaratkan dimensi eksplisit bila model ditentukan.
        $dimensions = $driver === Lab::OpenAICompatible
            ? 0
            : $provider->defaultEmbeddingsDimensions();

        // Panggil gateway langsung agar input/vektor tidak dikirim melalui event SDK.
        $response = $gateway->generateEmbeddings(
            $provider,
            $revision->model_id,
            [self::EMBEDDING_INPUT],
            $dimensions,
            ProbeTransportPolicy::TOTAL_TIMEOUT_SECONDS,
        );
        $embeddings = $response->embeddings;

        if (count($embeddings) !== 1 || ! is_array($embeddings[0] ?? null) || $embeddings[0] === []) {
            return ProviderProbeResult::failure(ProviderTestCategory::MalformedResponse);
        }

        foreach ($embeddings[0] as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return ProviderProbeResult::failure(ProviderTestCategory::MalformedResponse);
            }
        }

        return ProviderProbeResult::success(count($embeddings[0]));
    }

    private function classifyFailure(RequestException $exception): ProviderTestCategory
    {
        $status = $exception->response?->status();

        // 404 generik tidak otomatis berarti model tidak tersedia; hanya kode
        // terstruktur yang sempit yang boleh memetakan ke model_unavailable.
        if ($status === 404 && $exception->response?->json('error.code') === 'model_not_found') {
            return ProviderTestCategory::ModelUnavailable;
        }

        return match (true) {
            $status >= 300 && $status < 400 => ProviderTestCategory::RedirectRejected,
            $status === 401 || $status === 403 => ProviderTestCategory::AuthenticationRejected,
            $status === 402 => ProviderTestCategory::QuotaOrCreditExhausted,
            $status === 429 => ProviderTestCategory::RateLimited,
            $status === 408 || $status === 504 => ProviderTestCategory::Timeout,
            $status === 502 || $status === 503 || $status === 520 || $status === 522 || $status === 524 => ProviderTestCategory::ProviderUnavailable,
            default => ProviderTestCategory::ProviderRequestRejected,
        };
    }

    /**
     * Timeout transport dikenali dari errno cURL pada rantai exception, bukan
     * dari teks pesan (pesan provider tidak pernah dipakai untuk klasifikasi).
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

    private function gateway(Lab $driver, ProbeTransportPolicy $policy, Dispatcher $events): StepTextGateway&EmbeddingGateway
    {
        return match ($driver) {
            Lab::OpenAI => new SecureOpenAiGateway($events, $policy),
            Lab::OpenAICompatible => new SecureOpenAiCompatibleGateway($events, $policy),
            Lab::Gemini => new SecureGeminiGateway($events, $policy),
            Lab::OpenRouter => new SecureOpenRouterGateway($events, $policy),
            default => throw new \InvalidArgumentException('Driver provider tidak didukung.'),
        };
    }
}
