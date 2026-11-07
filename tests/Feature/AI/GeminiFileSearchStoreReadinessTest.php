<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\GeminiFileSearchOperations;
use App\Services\AI\GeminiStoreReadiness;
use App\Services\AI\GeminiStoreReadinessState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pemeriksaan read-only akses File Search Store: satu GET ke store yang
 * dikonfigurasi, identitas diverifikasi, dan tidak ada efek samping.
 */
class GeminiFileSearchStoreReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_CREDENTIAL = 'fictional-prime-gemini-key';

    private const ENV_FALLBACK_CREDENTIAL = 'fictional-env-fallback-key';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const STORE_URL = self::BASE_URL.'/fileSearchStores/store-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        config(['gemini_file_search.indexing.store' => 'store-1']);
    }

    public function test_accessible_store_with_matching_identity_uses_active_credential_only(): void
    {
        $revision = $this->activeRevision();
        config(['ai.providers.gemini.key' => self::ENV_FALLBACK_CREDENTIAL]);
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response([
            'name' => 'fileSearchStores/store-1',
            'displayName' => 'PRIME Fiction Store',
        ])]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::Accessible, $readiness->state);
        $this->assertSame('store_accessible', $readiness->reasonCode);
        $this->assertSame('fileSearchStores/store-1', $readiness->storeName);
        $this->assertTrue($readiness->accessible());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::STORE_URL
            && $request->header('x-goog-api-key') === [self::ACTIVE_CREDENTIAL]);
        Http::assertSentCount(1);
        $this->assertSame($revision->id, $revision->configuration->fresh()->active_revision_id);
    }

    public function test_missing_store_configuration_never_touches_network(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();

        foreach (['', '   ', 'fileSearchStores/', 'stores/store-1', 'store-1/../../x'] as $configured) {
            config(['gemini_file_search.indexing.store' => $configured]);

            $readiness = $this->checkReadiness();

            $this->assertSame(GeminiStoreReadinessState::NotConfigured, $readiness->state);
            $this->assertSame('store_not_configured', $readiness->reasonCode);
            $this->assertNull($readiness->storeName);
        }

        Http::assertNothingSent();
    }

    public function test_missing_active_gemini_credential_is_not_configured(): void
    {
        config(['ai.providers.gemini.key' => self::ENV_FALLBACK_CREDENTIAL]);
        Http::preventStrayRequests();

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::NotConfigured, $readiness->state);
        $this->assertSame('credential_not_configured', $readiness->reasonCode);
        $this->assertNull($readiness->storeName);
        Http::assertNothingSent();
    }

    public function test_non_gemini_active_provider_is_not_configured_without_fallback(): void
    {
        config(['ai.providers.gemini.key' => self::ENV_FALLBACK_CREDENTIAL]);
        $this->activeRevision(['driver' => 'openai']);
        Http::preventStrayRequests();

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::NotConfigured, $readiness->state);
        $this->assertSame('credential_not_configured', $readiness->reasonCode);
        Http::assertNothingSent();
    }

    public function test_unsafe_custom_endpoint_is_unavailable_without_request(): void
    {
        $this->activeRevision(['base_url' => 'http://127.0.0.1/v1beta']);
        Http::preventStrayRequests();

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::Unavailable, $readiness->state);
        $this->assertSame('transport_rejected', $readiness->reasonCode);
        Http::assertNothingSent();
    }

    public function test_denied_credential_maps_to_access_denied_without_leaking_details(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response([
            'error' => ['code' => 403, 'message' => 'Permission denied for key '.self::ACTIVE_CREDENTIAL],
        ], 403)]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::AccessDenied, $readiness->state);
        $this->assertSame('access_denied', $readiness->reasonCode);
        $this->assertNull($readiness->storeName);
        $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, serialize($readiness));
    }

    public function test_unauthorized_credential_maps_to_access_denied(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['error' => ['code' => 401]], 401)]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::AccessDenied, $readiness->state);
        $this->assertSame('access_denied', $readiness->reasonCode);
    }

    public function test_absent_store_maps_to_missing_store(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['error' => ['code' => 404]], 404)]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::MissingStore, $readiness->state);
        $this->assertSame('store_not_found', $readiness->reasonCode);
        $this->assertNull($readiness->storeName);
    }

    public function test_rate_limited_response_maps_to_unavailable(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['error' => ['code' => 429]], 429)]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::Unavailable, $readiness->state);
        $this->assertSame('provider_unavailable', $readiness->reasonCode);
    }

    public function test_server_error_maps_to_unavailable(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::sequence()
            ->push(['error' => ['code' => 500]], 500)
            ->push(['error' => ['code' => 503]], 503)]);

        foreach ([500, 503] as $expectedStatus) {
            $readiness = $this->checkReadiness();

            $this->assertSame(GeminiStoreReadinessState::Unavailable, $readiness->state);
            $this->assertSame('provider_unavailable', $readiness->reasonCode, 'HTTP '.$expectedStatus);
        }

        Http::assertSentCount(2);
    }

    public function test_connection_failure_maps_to_unavailable_without_retry(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::failedConnection()]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::Unavailable, $readiness->state);
        $this->assertSame('connection_failed', $readiness->reasonCode);
        Http::assertSentCount(1);
    }

    public function test_malformed_success_payload_maps_to_invalid_response(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response('not-json', 200, ['Content-Type' => 'application/json'])]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::InvalidResponse, $readiness->state);
        $this->assertSame('provider_response_invalid', $readiness->reasonCode);
        $this->assertNull($readiness->storeName);
    }

    public function test_success_with_other_store_identity_is_rejected(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::STORE_URL => Http::response(['name' => 'fileSearchStores/store-2'])]);

        $readiness = $this->checkReadiness();

        $this->assertSame(GeminiStoreReadinessState::InvalidResponse, $readiness->state);
        $this->assertSame('provider_response_invalid', $readiness->reasonCode);
        $this->assertNull($readiness->storeName);
    }

    public function test_readiness_request_is_read_only(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['name' => 'fileSearchStores/store-1'])]);

        $this->checkReadiness();

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        Http::assertSentCount(1);
    }

    private function checkReadiness(): GeminiStoreReadiness
    {
        return app(GeminiFileSearchOperations::class)->checkStoreReadiness();
    }

    private function activeRevision(array $overrides = []): AiProviderConfigurationRevision
    {
        $configuration = AiProviderConfiguration::query()->create([
            'display_name' => 'Fictional Gemini',
            'purpose' => 'chat',
        ]);
        $revision = $configuration->revisions()->make(array_replace([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.8-flash',
            'credential' => self::ACTIVE_CREDENTIAL,
            'enabled' => true,
        ], $overrides))->forceFill([
            'revision_number' => 1,
            'test_state' => 'passed',
            'test_attempt_id' => 'fictional-attempt-id',
            'test_started_at' => now(),
            'tested_at' => now(),
        ]);
        $revision->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        return $revision;
    }
}
