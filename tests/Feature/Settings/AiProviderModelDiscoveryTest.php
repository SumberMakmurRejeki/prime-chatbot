<?php

namespace Tests\Feature\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'manage_ai' => false]);
    }

    public function test_model_discovery_sends_bearer_token_and_returns_normalized_deduplicated_ids_without_persisting_state(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'provider.example.test/v1/models' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => ' zeta '],
                    ['id' => 'alpha'],
                    ['id' => 'zeta'],
                    ['id' => '  '],
                    ['name' => 'missing-id'],
                    ['id' => 12],
                    ['id' => '123'],
                    'plain-model',
                ],
            ]),
        ]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager);
        $beforeConfigurations = AiProviderConfiguration::query()->count();
        $beforeRevisions = AiProviderConfigurationRevision::query()->count();

        $response = $this->post('/settings/ai/providers/models', [
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'discovery-secret-never-persist',
        ])->assertOk()->assertExactJson(['model_ids' => ['123', 'alpha', 'plain-model', 'zeta']]);

        $this->assertIsString($response->json('model_ids.0'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://provider.example.test/v1/models'
            && $request->hasHeader('Authorization', 'Bearer discovery-secret-never-persist')
            && $request->hasHeader('Accept', 'application/json'));
        $this->assertSame($beforeConfigurations, AiProviderConfiguration::query()->count());
        $this->assertSame($beforeRevisions, AiProviderConfigurationRevision::query()->count());
        $this->assertStringNotContainsString('discovery-secret-never-persist', (string) $response->getContent());
        $this->assertStringNotContainsString('discovery-secret-never-persist', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('user_activity_logs')->where('module_name', 'ai_provider_configuration')->count());
    }

    public function test_successful_discovery_does_not_change_existing_revision_test_or_activation_state(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $snapshot = $revision->only(['test_state', 'test_attempt_id', 'tested_at', 'test_failure_category', 'enabled']);
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/models' => Http::response(['data' => [['id' => 'another-model']]])]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->post('/settings/ai/providers/models', [
            'base_url' => 'https://provider.example.test/v1',
            'provider_id' => $configuration->id,
            'revision_id' => $revision->id,
        ])->assertOk()->assertExactJson(['model_ids' => ['another-model']]);

        $this->assertSame($snapshot, $revision->fresh()->only(array_keys($snapshot)));
        $this->assertNull($configuration->fresh()->active_revision_id);
        $this->assertNull($configuration->fresh()->previous_known_good_revision_id);
    }

    public function test_blank_key_can_only_reuse_the_exact_owned_revision_credential(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload(['credential' => 'saved-discovery-secret']))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/models' => Http::response(['data' => [['id' => 'model']]])]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->post('/settings/ai/providers/models', [
            'base_url' => 'https://provider.example.test/v1',
            'provider_id' => $configuration->id,
            'revision_id' => $revision->id,
        ])->assertOk();
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer saved-discovery-secret'));

        $this->post('/settings/ai/providers/models', [
            'base_url' => 'https://provider.example.test/v1',
            'provider_id' => $configuration->id,
            'revision_id' => $revision->id + 999,
        ])->assertNotFound();
        $sentBeforeCrossHost = collect(Http::recorded())->count();
        $this->post('/settings/ai/providers/models', [
            'base_url' => 'https://attacker.example.test/v1',
            'provider_id' => $configuration->id,
            'revision_id' => $revision->id,
        ])->assertStatus(422)->assertExactJson(['message' => 'API Key ditolak.']);
        $this->assertSame($sentBeforeCrossHost, collect(Http::recorded())->count());

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'attacker.example.test'));
    }

    public function test_only_active_admin_with_manage_ai_can_discover_models(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $payload = ['base_url' => 'https://provider.example.test/v1', 'credential' => 'never-return-this'];
        $this->actingAs($this->admin)->post('/settings/ai/providers/models', $payload)->assertForbidden();
        $this->actingAs($this->operator)->post('/settings/ai/providers/models', $payload)->assertRedirect('/403');
        auth()->logout();
        $this->post('/settings/ai/providers/models', $payload)->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_unsafe_urls_are_rejected_before_network_io(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager);

        foreach (['http://provider.example.test/v1', 'https://localhost/v1', 'https://provider.example.test:8443/v1'] as $baseUrl) {
            $this->post('/settings/ai/providers/models', ['base_url' => $baseUrl])->assertSessionHasErrors('base_url');
        }

        Http::assertNothingSent();
    }

    public function test_authentication_failure_returns_safe_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/models' => Http::response(['error' => ['message' => 'secret raw upstream body']], 401)]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->actingAs($this->manager)->post('/settings/ai/providers/models', $this->discoveryPayload())
            ->assertStatus(422)->assertExactJson(['message' => 'API Key ditolak.']);
    }

    public function test_invalid_json_returns_safe_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/models' => Http::response('<html>secret raw upstream body</html>', 200)]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->actingAs($this->manager)->post('/settings/ai/providers/models', $this->discoveryPayload())
            ->assertStatus(422)->assertExactJson(['message' => 'Respons daftar model tidak valid.']);
    }

    public function test_redirect_response_is_not_followed_and_private_dns_is_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/models' => Http::response(['data' => [['id' => 'model']]], 302, ['Location' => 'https://redirect.example.test/models'])]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers/models', $this->discoveryPayload())->assertStatus(422);
        Http::assertSentCount(1);

        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['127.0.0.1']));
        $this->post('/settings/ai/providers/models', $this->discoveryPayload())->assertStatus(422)->assertExactJson(['message' => 'Base URL tidak aman.']);
        Http::assertSentCount(1);
    }

    public function test_oversized_model_ids_are_discarded(): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/models' => Http::response(['data' => [['id' => str_repeat('x', 256)], ['id' => 'valid']]])]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->actingAs($this->manager)->post('/settings/ai/providers/models', $this->discoveryPayload())
            ->assertOk()->assertExactJson(['model_ids' => ['valid']]);
    }

    public function test_timeout_is_categorized_without_echoing_transport_details(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectException(
            'cURL error 28: timeout with discovery-secret',
            new Psr7Request('GET', 'https://provider.example.test/v1/models'),
            null,
            ['errno' => CURLE_OPERATION_TIMEDOUT],
        ));
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers/models', $this->discoveryPayload())
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Permintaan terlalu lama.'])
            ->assertDontSee('discovery-secret');
    }

    public function test_manual_model_id_save_needs_no_discovery(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload(['model_id' => 'manual/exact-model']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $revision = AiProviderConfigurationRevision::query()->firstOrFail();
        $this->assertSame('manual/exact-model', $revision->model_id);
        $this->assertSame('untested', $revision->test_state);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'display_name' => 'Discovery test provider',
            'purpose' => 'chat',
            'model_id' => 'manual/model-id',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => null,
        ], $overrides);
    }

    /** @return array<string, string> */
    private function discoveryPayload(): array
    {
        return ['base_url' => 'https://provider.example.test/v1', 'credential' => 'discovery-secret'];
    }
}
