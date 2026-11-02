<?php

namespace App\Services\AI\Gateway;

use App\Services\AI\ProbeTransportPolicy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Providers\Provider;

final class SecureOpenAiCompatibleGateway extends OpenAiCompatibleGateway
{
    use HardensOutboundTransport;

    public function __construct(Dispatcher $events, ProbeTransportPolicy $policy)
    {
        $this->policy = $policy;
        parent::__construct($events);
    }

    /**
     * Memastikan setiap embedding terikat pada indeks input yang benar sebelum dipetakan.
     * Vektor tidak boleh pernah dipasangkan ke chunk yang salah akibat urutan respons provider.
     *
     * @return array<int, array<int, float>>
     */
    protected function parseEmbeddings(array $data, int $expectedCount): array
    {
        $entries = $data['data'] ?? null;

        if (! is_array($entries) || ! array_is_list($entries) || count($entries) !== $expectedCount) {
            throw new AiException('OpenAI-compatible Error: [invalid_response] The response did not contain indexed embeddings.');
        }

        $indexed = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! array_key_exists('index', $entry) || ! is_int($entry['index'])
                || $entry['index'] < 0 || $entry['index'] >= $expectedCount || array_key_exists($entry['index'], $indexed)
            ) {
                throw new AiException('OpenAI-compatible Error: [invalid_response] The response contained invalid embedding indices.');
            }

            $indexed[$entry['index']] = $entry;
        }

        if (count($indexed) !== $expectedCount) {
            throw new AiException('OpenAI-compatible Error: [invalid_response] The response contained incomplete embedding indices.');
        }

        ksort($indexed);

        return parent::parseEmbeddings(['data' => array_values($indexed)], $expectedCount);
    }

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return $this->harden(parent::client($provider, $timeout), $provider);
    }
}
