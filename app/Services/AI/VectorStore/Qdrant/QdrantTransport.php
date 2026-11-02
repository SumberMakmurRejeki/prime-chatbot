<?php

namespace App\Services\AI\VectorStore\Qdrant;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Batas transport keluar untuk Qdrant.
 *
 * Setiap permintaan Qdrant memakai klien ini: JSON, timeout koneksi dan
 * permintaan eksplisit, redirect tidak diikuti, tanpa retry otomatis, dan
 * API key hanya sebagai header. Redirect adalah sinyal keamanan, jadi respons
 * 3xx diperlakukan gagal oleh adapter, bukan diikuti.
 */
final class QdrantTransport
{
    public function __construct(
        private readonly Factory $http,
        private readonly QdrantConfig $config,
    ) {}

    public function client(): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->config->url)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->config->connectTimeout)
            ->timeout($this->config->requestTimeout)
            ->withoutRedirecting();

        if ($this->config->apiKey === null) {
            return $request;
        }

        return $request->withHeader('api-key', $this->config->apiKey);
    }
}
