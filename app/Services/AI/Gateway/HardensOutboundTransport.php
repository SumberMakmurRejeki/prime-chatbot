<?php

namespace App\Services\AI\Gateway;

use App\Services\AI\ProbeTransportPolicy;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Providers\Provider;

/**
 * Kontrak umum gateway SDK yang diperkuat transport.
 *
 * Setiap subclass hanya menumpuk batas jaringan PRIME di atas `client()`
 * bawaan SDK; header, base URL, dan parsing respons tetap milik SDK.
 */
trait HardensOutboundTransport
{
    protected ProbeTransportPolicy $policy;

    protected function harden(PendingRequest $client, Provider $provider): PendingRequest
    {
        return $this->policy->apply($client, $this->baseUrl($provider));
    }
}
