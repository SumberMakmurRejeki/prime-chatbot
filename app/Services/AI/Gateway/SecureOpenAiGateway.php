<?php

namespace App\Services\AI\Gateway;

use App\Services\AI\ProbeTransportPolicy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\Provider;

final class SecureOpenAiGateway extends OpenAiGateway
{
    use HardensOutboundTransport;

    public function __construct(Dispatcher $events, ProbeTransportPolicy $policy)
    {
        $this->policy = $policy;
        parent::__construct($events);
    }

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return $this->harden(parent::client($provider, $timeout), $provider);
    }
}
