<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;

/*
 * Transport aman provider dengan timeout 22 detik, DNS pinning, TLS aktif,
 * tanpa redirect/proxy/retry, dan batas respons per operasi. Batas baku 256
 * KiB untuk probe; embedding dapat meminta batas lebih besar yang tetap
 * dibatasi konstruktor agar satu batch vektor tidak dipotong saat diterima.
 *
 * Satu instance dipakai per operasi agar status penolakan tidak bocor antar-
 * permintaan atau tersimpan di state global.
 */
final class ProbeTransportPolicy
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TOTAL_TIMEOUT_SECONDS = 22;

    public const MAX_RESPONSE_BODY_BYTES = 256 * 1024;

    private bool $redirectRejected = false;

    private bool $declaredSizeExceeded = false;

    private bool $bodyCeilingExceeded = false;

    public function __construct(
        private readonly AiEndpointPinner $pinner,
        private readonly int $maxResponseBodyBytes = self::MAX_RESPONSE_BODY_BYTES,
    ) {
        if ($maxResponseBodyBytes < 1 || $maxResponseBodyBytes > 16 * 1024 * 1024) {
            throw new \InvalidArgumentException('Response body limit is outside the bounded policy range.');
        }
    }

    /**
     * Validasi URL sebelum ada I/O apa pun. Dipanggil di awal probe agar
     * endpoint yang tidak aman gagal sebagai unsafe_base_url, bukan sebagai
     * kegagalan jaringan.
     */
    public function validateBaseUrl(string $baseUrl): void
    {
        $this->assertSafeUrl($baseUrl);
    }

    /**
     * Bangun entr CURLOPT_RESOLVE dan pasang batas transport pada PendingRequest.
     * Dipakai oleh subclass gateway SDK; tidak mengubah HttpClient global Laravel.
     */
    public function apply(PendingRequest $client, string $baseUrl): PendingRequest
    {
        $parts = $this->assertSafeUrl($baseUrl);

        $pins = $this->pinner->pin($parts['host'], $parts['port']);
        $policy = $this;

        return $client
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TOTAL_TIMEOUT_SECONDS)
            // Redirect tidak pernah diikuti; respons 3xx ditolak sebagai sinyal keamanan.
            ->withoutRedirecting()
            ->withOptions([
                // Proxy dimatikan agar tujuan keluar selalu endpoint tervalidasi.
                'proxy' => '',
                'curl' => $pins === [] ? [] : [CURLOPT_RESOLVE => $pins],
                'sink' => new BoundedResponseSink($this->maxResponseBodyBytes, function () use ($policy): void {
                    $policy->bodyCeilingExceeded = true;
                }),
                'on_headers' => function ($response) use ($policy): void {
                    $status = $response->getStatusCode();

                    if ($status >= 300 && $status < 400) {
                        $policy->redirectRejected = true;

                        throw new UnsafeEndpointException('Provider mengembalikan pengalihan.', ProviderTestCategory::RedirectRejected);
                    }

                    $declared = $response->getHeaderLine('Content-Length');

                    if ($declared !== '' && (int) $declared > $this->maxResponseBodyBytes) {
                        $policy->declaredSizeExceeded = true;

                        throw new UnsafeEndpointException('Ukuran respons melampaui batas aman.', ProviderTestCategory::MalformedResponse);
                    }
                },
            ]);
    }

    public function redirectRejected(): bool
    {
        return $this->redirectRejected;
    }

    public function responseTooLarge(): bool
    {
        return $this->declaredSizeExceeded || $this->bodyCeilingExceeded;
    }

    /**
     * Validasi sintaks dan bentuk URL. Custom endpoint hanya boleh HTTPS port
     * 443 dengan hostname eksplisit, tanpa userinfo/query/fragment/kontrol.
     *
     * @return array{host: string, port: int}
     */
    private function assertSafeUrl(string $baseUrl): array
    {
        $reject = fn (string $reason): never => throw new UnsafeEndpointException($reason, ProviderTestCategory::UnsafeBaseUrl);

        if ($baseUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            $reject('Base URL kosong atau memuat karakter kontrol.');
        }

        $parts = parse_url($baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $reject('Base URL tidak dapat diparse.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            $reject('Custom endpoint wajib HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $reject('Base URL tidak boleh memuat userinfo.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            $reject('Base URL tidak boleh memuat query atau fragment.');
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            $reject('Custom endpoint hanya boleh memakai port 443.');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            $reject('Host tujuan tidak diizinkan.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Hostname harus memuat titik dan TLD alfabet; representasi numerik
            // ambigu (desimal/hex/oktal) ditolak karena bisa menunjuk loopback.
            if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
                $reject('Hostname tidak valid atau ambigu.');
            }
        }

        return ['host' => $host, 'port' => 443];
    }
}
