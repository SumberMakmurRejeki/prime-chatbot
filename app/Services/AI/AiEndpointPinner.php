<?php

namespace App\Services\AI;

use Laravel\Ai\Files\UntrustedUrl;

/**
 * Memvalidasi host tujuan dan membangun entr CURLOPT_RESOLVE.
 *
 * Pewarisan dipakai hanya untuk memakai ulang klasifikasi alamat SDK
 * (`isBlocked`) agar daftar range terlarang tidak diduplikasi. Resolusi sendiri
 * memakai resolver terbatas (BoundedDnsResolver) yang bisa diganti di test.
 *
 * @internal AI-1B2
 */
final class AiEndpointPinner extends UntrustedUrl
{
    public function __construct(private readonly mixed $dnsResolver = null) {}

    /**
     * @return list<string>
     */
    public function pin(string $host, int $port): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isBlocked($host)) {
                throw new UnsafeEndpointException('Alamat tujuan tidak diizinkan.', ProviderTestCategory::UnsafeBaseUrl);
            }

            return [];
        }
        try {
            $addresses = $this->dnsResolver !== null
                ? ($this->dnsResolver)($host)
                : (new BoundedDnsResolver)->resolve($host);
        } catch (\RuntimeException) {
            // Melewati deadline resolusi; kategori disimpan sebagai timeout.
            throw new UnsafeEndpointException('Resolusi host melewati batas waktu.', ProviderTestCategory::Timeout);
        }

        if ($addresses === []) {
            throw new UnsafeEndpointException('Host tidak dapat diresolusi.', ProviderTestCategory::UnsafeBaseUrl);
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                throw new UnsafeEndpointException('Host me-resolve ke address yang tidak diizinkan.', ProviderTestCategory::UnsafeBaseUrl);
            }
        }

        return [$host.':'.$port.':'.implode(',', array_map(self::formatAddress(...), $addresses))];
    }

    private static function formatAddress(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }
}
