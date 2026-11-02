<?php

namespace App\Services\AI\VectorStore\Qdrant;

use App\Services\AI\VectorStore\VectorStoreError;
use App\Services\AI\VectorStore\VectorStoreException;

final readonly class QdrantConfig
{
    public function __construct(
        public string $url,
        public ?string $apiKey,
        public string $collection,
        public int $connectTimeout,
        public int $requestTimeout,
        public int $maxUpsertPoints,
        public int $maxRetrievalLimit,
    ) {
        // Endpoint infrastruktur self-hosted: loopback/privat sah, tetapi
        // kredensial tertanam, query/fragment, dan path tambahan ditolak agar
        // URL tetap origin murni dan tidak ada secret yang bocor ke log.
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true)
            || preg_match('/[\x00-\x1f\x7f\s]/', $url)
        ) {
            throw new VectorStoreException('Endpoint Qdrant tidak valid.', VectorStoreError::Configuration);
        }

        // Collection dipakai sebagai segmen path REST; charset ketat mencegah
        // injeksi path dan kebingungan routing.
        if (! preg_match('/^[A-Za-z0-9._-]{1,255}$/', $collection)
            || $connectTimeout < 1
            || $requestTimeout < 1
            || $maxUpsertPoints < 1
            || $maxRetrievalLimit < 1
        ) {
            throw new VectorStoreException('Konfigurasi Qdrant tidak valid.', VectorStoreError::Configuration);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $apiKey = $config['api_key'] ?? null;

        return new self(
            url: (string) ($config['url'] ?? ''),
            apiKey: is_string($apiKey) && trim($apiKey) !== '' ? $apiKey : null,
            collection: (string) ($config['collection'] ?? ''),
            connectTimeout: (int) ($config['connect_timeout'] ?? 0),
            requestTimeout: (int) ($config['request_timeout'] ?? 0),
            maxUpsertPoints: (int) ($config['max_upsert_points'] ?? 0),
            maxRetrievalLimit: (int) ($config['max_retrieval_limit'] ?? 0),
        );
    }
}
