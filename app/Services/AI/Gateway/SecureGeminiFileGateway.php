<?php

namespace App\Services\AI\Gateway;

use App\Services\AI\ProbeTransportPolicy;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Gemini\GeminiFileGateway;
use Laravel\Ai\Providers\Provider;

/**
 * Gateway berkas Gemini dengan batas transport PRIME (HTTPS, pin DNS, tanpa
 * redirect/proxy/retry, timeout, dan batas ukuran respons).
 */
final class SecureGeminiFileGateway extends GeminiFileGateway
{
    use HardensOutboundTransport;

    public function __construct(ProbeTransportPolicy $policy)
    {
        $this->policy = $policy;
    }

    protected function client(Provider $provider): PendingRequest
    {
        return $this->harden(parent::client($provider), $provider);
    }
}
