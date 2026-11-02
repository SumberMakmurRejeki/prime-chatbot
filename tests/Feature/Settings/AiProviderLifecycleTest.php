<?php

namespace Tests\Feature\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\BoundedResponseSink;
use App\Services\AI\ProbeTransportPolicy;
use App\Services\AI\ProviderCapabilityProbe;
use App\Services\AI\ProviderProbeResult;
use App\Services\AI\UnsafeEndpointException;
use App\Services\Settings\AiProviderLifecycleService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class AiProviderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
    }

    public function test_provider_purpose_cannot_change_after_a_test_attempt_has_started(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $revision->forceFill([
            'test_state' => 'testing',
            'test_attempt_id' => 'attempt-1',
            'test_started_at' => now(),
        ])->save();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload([
            'purpose' => 'embedding',
        ]))->assertSessionHasErrors('purpose');

        $this->assertSame('chat', $configuration->fresh()->purpose);
        $this->assertSame(1, $configuration->revisions()->count());
    }

    public function test_test_connection_records_only_safe_exact_revision_result_then_allows_activation_and_restore(): void
    {
        $secret = 'test-secret-value';
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload(['credential' => $secret]))->assertRedirect();

        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $probe = $this->createMock(ProviderCapabilityProbe::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with($this->callback(fn (AiProviderConfigurationRevision $candidate): bool => $candidate->is($revision)), 'chat')
            ->willReturn(ProviderProbeResult::success());
        $this->app->instance(ProviderCapabilityProbe::class, $probe);

        $response = $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test');
        $response->assertRedirect('/settings/ai/providers')->assertSessionHas('flash_success');
        $this->assertSame('passed', $revision->fresh()->test_state);
        $this->assertSame('success', json_decode(DB::table('user_activity_logs')->latest('id')->value('new_values'), true)['category']);
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/activate')->assertRedirect();
        $this->assertSame($revision->id, $configuration->fresh()->active_revision_id);
        $this->assertNull($configuration->fresh()->previous_known_good_revision_id);
    }

    public function test_restore_consumes_previous_pointer_and_rejects_stale_expected_pointers(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();
        $first->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-first', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $configuration->forceFill(['active_revision_id' => $first->id])->save();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload(['model_id' => 'vendor/second-model']))->assertRedirect();
        $second = $configuration->revisions()->whereKeyNot($first->id)->firstOrFail();
        $second->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-second', 'test_started_at' => now(), 'tested_at' => now()])->save();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$second->id.'/activate')->assertRedirect();
        $this->assertSame($second->id, $configuration->fresh()->active_revision_id);
        $this->assertSame($first->id, $configuration->fresh()->previous_known_good_revision_id);

        $this->post('/settings/ai/providers/'.$configuration->id.'/restore', [
            'expected_active_revision_id' => $second->id,
            'expected_previous_revision_id' => $first->id,
        ])->assertRedirect();
        $this->assertSame($first->id, $configuration->fresh()->active_revision_id);
        $this->assertNull($configuration->fresh()->previous_known_good_revision_id);

        $this->post('/settings/ai/providers/'.$configuration->id.'/restore', [
            'expected_active_revision_id' => $second->id,
            'expected_previous_revision_id' => $first->id,
        ])->assertStatus(409);
    }

    public function test_activation_rejects_wrong_provider_and_untested_revisions(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload(['display_name' => 'One']))->assertRedirect();
        $first = AiProviderConfiguration::query()->firstOrFail();
        $revision = $first->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$first->id.'/revisions/'.$revision->id.'/activate')->assertStatus(422);

        $this->post('/settings/ai/providers', $this->payload(['display_name' => 'Two']))->assertRedirect();
        $second = AiProviderConfiguration::query()->where('display_name', 'Two')->firstOrFail();
        $this->post('/settings/ai/providers/'.$second->id.'/revisions/'.$revision->id.'/activate')->assertNotFound();
    }

    public function test_lifecycle_actions_retain_existing_admin_ai_authorization_boundary(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => false]);
        $this->actingAs($admin)->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertForbidden();
        $this->assertSame('untested', $revision->fresh()->test_state);
    }

    public function test_blank_or_filtered_generation_does_not_pass(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'provider.example.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '   '], 'finish_reason' => 'stop']]])
                ->push(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null], 'finish_reason' => 'content_filter']]]),
        ]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertRedirect();
        $this->assertSame('generation_rejected', $revision->fresh()->test_failure_category);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertRedirect();
        $this->assertSame('generation_rejected', $revision->fresh()->test_failure_category);
        $this->assertNull($configuration->fresh()->active_revision_id);
    }

    public function test_embedding_probe_persists_only_the_vector_length(): void
    {
        $vector = [0.123456789, -0.987654321, 0.5];
        Http::preventStrayRequests();
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response([
                'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => $vector]],
                'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
            ]),
        ]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload([
            'purpose' => 'embedding',
            'model_id' => 'vendor/embed-model',
        ]))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')
            ->assertRedirect('/settings/ai/providers')
            ->assertSessionHas('flash_success', 'Primary AI · Revisi 1 berhasil diuji.');

        $this->assertSame('passed', $revision->fresh()->test_state);
        $this->assertSame(3, $revision->fresh()->tested_embedding_dimensions);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.example.test/v1/embeddings'
            && $request['model'] === 'vendor/embed-model'
            && $request['input'] === ['PRIME connection test']);

        $persisted = json_encode([
            DB::table('ai_provider_configuration_revisions')->get()->toArray(),
            DB::table('user_activity_logs')->get()->toArray(),
            session()->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('0.123456789', $persisted);
        $this->assertStringNotContainsString('-0.987654321', $persisted);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('providerFailureCases')]
    public function test_provider_failures_map_to_allowlisted_categories(int $status, array $body, string $expected): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/chat/completions' => Http::response($body, $status)]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertRedirect();

        $this->assertSame('failed', $revision->fresh()->test_state);
        $this->assertSame($expected, $revision->fresh()->test_failure_category);
        $this->assertStringNotContainsString($body['error']['message'] ?? '', (string) session('flash_error'));
    }

    /** @return array<string, array{int, array<string, mixed>, string}> */
    public static function providerFailureCases(): array
    {
        return [
            'authentication rejected' => [401, ['error' => ['message' => 'Incorrect API key provided: test-secret-value', 'code' => 'invalid_api_key']], 'authentication_rejected'],
            'rate limited' => [429, ['error' => ['message' => 'Rate limit reached']], 'rate_limited'],
            'credit exhausted' => [402, ['error' => ['message' => 'Insufficient credits']], 'quota_or_credit_exhausted'],
            'upstream unavailable' => [503, ['error' => ['message' => 'Service unavailable']], 'provider_unavailable'],
            'generic rejection' => [400, ['error' => ['message' => 'Malformed request']], 'provider_request_rejected'],
            'generic not found' => [404, ['error' => ['message' => 'No such route']], 'provider_request_rejected'],
            'structured model missing' => [404, ['error' => ['message' => 'The model does not exist', 'code' => 'model_not_found']], 'model_unavailable'],
        ];
    }

    public function test_malformed_and_timed_out_provider_responses_are_sanitized(): void
    {
        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/chat/completions' => Http::response('<html>not json</html>', 200)]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertRedirect();
        $this->assertSame('malformed_response', $revision->fresh()->test_failure_category);

        Http::fake(fn () => throw new ConnectException(
            'cURL error 28: Operation timed out after 22000 milliseconds with test-secret-value',
            new Psr7Request('POST', 'https://provider.example.test/v1/chat/completions'),
            null,
            ['errno' => CURLE_OPERATION_TIMEDOUT],
        ));
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertRedirect();
        $this->assertSame('timeout', $revision->fresh()->test_failure_category);
        $this->assertStringNotContainsString('test-secret-value', (string) session('flash_error'));
    }

    public function test_unreachable_provider_maps_to_provider_unavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect with test-secret-value'));
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')->assertRedirect();

        $this->assertSame('provider_unavailable', $revision->fresh()->test_failure_category);
        $this->assertStringNotContainsString('test-secret-value', json_encode([
            DB::table('user_activity_logs')->get()->toArray(),
            session()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function test_chat_probe_uses_saved_model_and_accepts_nonblank_protocol_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'provider.example.test/v1/chat/completions' => Http::response([
                'id' => 'probe-response',
                'model' => 'vendor/chat-model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Connected successfully.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
            ]),
        ]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')
            ->assertRedirect('/settings/ai/providers');
        $this->assertSame('passed', $revision->fresh()->test_state, $revision->fresh()->test_failure_category ?? 'no category');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.example.test/v1/chat/completions'
            && $request['model'] === 'vendor/chat-model'
            && $request['max_tokens'] <= 64
            && $request->hasHeader('Authorization', 'Bearer test-secret-value'));
    }

    public function test_custom_endpoint_policy_rejects_local_addresses_and_bounds_response_sink(): void
    {
        $pinner = new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34', '10.0.0.1']);
        try {
            $pinner->pin('provider.example.test', 443);
            $this->fail('Mixed public/private DNS answers must be rejected.');
        } catch (UnsafeEndpointException $exception) {
            $this->assertSame('unsafe_base_url', $exception->category()->value);
        }

        $sink = new BoundedResponseSink(4, static function (): void {});
        $this->assertSame(4, $sink->write('safe'));
        $this->assertSame(0, $sink->write('!'));
        $this->assertSame('safe', (string) $sink);

        $this->expectException(UnsafeEndpointException::class);
        (new ProbeTransportPolicy(new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34'])))->validateBaseUrl('http://provider.example.test/v1');

    }

    public function test_transport_response_limit_is_bounded_per_policy_instance(): void
    {
        $pinner = new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']);

        foreach ([0, -1, 16 * 1024 * 1024 + 1] as $limit) {
            try {
                new ProbeTransportPolicy($pinner, $limit);
                $this->fail('Response body limits outside the bounded range must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Response body limit is outside the bounded policy range.', $exception->getMessage());
            }
        }

        $options = (new ProbeTransportPolicy($pinner, 8 * 1024 * 1024))
            ->apply($this->app->make(Factory::class)->withOptions([]), 'https://provider.example.test/v1')
            ->getOptions();

        $this->assertSame(8 * 1024 * 1024, $this->sinkLimit($options['sink']));
        $this->assertSame(ProbeTransportPolicy::TOTAL_TIMEOUT_SECONDS, $options['timeout']);
    }

    private function sinkLimit(object $sink): int
    {
        $property = new \ReflectionProperty($sink, 'limit');
        $property->setAccessible(true);

        return $property->getValue($sink);
    }

    public function test_stale_probe_completion_cannot_overwrite_newer_attempt(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $revision->forceFill(['test_state' => 'testing', 'test_attempt_id' => 'new-attempt', 'test_started_at' => now()])->save();

        $service = app(AiProviderLifecycleService::class);
        $method = new ReflectionMethod($service, 'complete');
        $result = $method->invoke($service, request(), $configuration->id, $revision->id, 'old-attempt', 'chat', ProviderProbeResult::success());

        $this->assertTrue($result->superseded);
        $this->assertSame('testing', $revision->fresh()->test_state);
        $this->assertSame('new-attempt', $revision->fresh()->test_attempt_id);
    }

    public function test_superseded_test_completion_flashes_dedicated_message_and_changes_nothing(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();

        $probe = $this->createMock(ProviderCapabilityProbe::class);
        $probe->expects($this->once())->method('probe')->willReturnCallback(function () use ($revision): ProviderProbeResult {
            AiProviderConfigurationRevision::query()->whereKey($revision->id)->update(['test_attempt_id' => 'newer-attempt']);

            return ProviderProbeResult::success();
        });
        $this->app->instance(ProviderCapabilityProbe::class, $probe);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test')
            ->assertRedirect('/settings/ai/providers')
            ->assertSessionHas('flash_error', 'Hasil uji koneksi diabaikan karena ada percobaan uji yang lebih baru.');

        $this->assertSame('testing', $revision->fresh()->test_state);
        $this->assertSame('newer-attempt', $revision->fresh()->test_attempt_id);
        $this->assertNull($revision->fresh()->tested_at);
        $this->assertSame(0, DB::table('user_activity_logs')->where('action', 'connection_test')->count());
    }

    public function test_index_renders_lifecycle_actions_and_previous_known_good_without_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
            ]),
        ]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/test')->assertRedirect();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/activate')->assertRedirect();
        $this->assertSame($first->id, $configuration->fresh()->active_revision_id);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload())->assertRedirect();
        $second = $configuration->revisions()->latest('revision_number')->firstOrFail();
        $untestedPage = $this->get('/settings/ai/providers')->assertOk()
            ->assertSee('action="'.route('settings-ai-providers.test', [$configuration, $second]).'"', false)
            ->assertSee('Uji koneksi')
            ->assertSee('Model: vendor/chat-model');
        $this->assertSame($first->id, $configuration->fresh()->active_revision_id);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$second->id.'/test')->assertRedirect();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$second->id.'/activate')->assertRedirect();
        $this->assertSame($second->id, $configuration->fresh()->active_revision_id);
        $this->assertSame($first->id, $configuration->fresh()->previous_known_good_revision_id);

        $page = $this->get('/settings/ai/providers')->assertOk()
            ->assertSee('Model: vendor/chat-model')
            ->assertSee('Koneksi berhasil')
            ->assertSee('Aktif')
            ->assertSee('Uji selesai:')
            ->assertSee('Uji ulang')
            ->assertSee('Riwayat konfigurasi (2)')
            ->assertSee('data-modal-open="ai-provider-revision-'.$configuration->id.'-modal"', false)
            ->assertSee('action="'.route('settings-ai-providers.test', [$configuration, $second]).'"', false)
            ->assertSee('action="'.route('settings-ai-providers.activate', [$configuration, $first]).'"', false)
            ->assertSee('Pulihkan konfigurasi sebelumnya')
            ->assertSee('name="expected_active_revision_id" value="'.$second->id.'"', false)
            ->assertSee('name="expected_previous_revision_id" value="'.$first->id.'"', false)
            ->assertDontSee('test-secret-value');

        $this->assertStringNotContainsString('test-secret-value', (string) $page->getContent());
        $this->assertStringNotContainsString('<details class="mt-5 border-t border-[var(--color-prime-border)] pt-3" open', (string) $page->getContent());
    }

    public function test_index_renders_safe_failure_label_for_failed_revision(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $revision->forceFill([
            'test_state' => 'failed',
            'test_attempt_id' => 'attempt-failed',
            'test_started_at' => now(),
            'tested_at' => now(),
            'test_failure_category' => 'rate_limited',
        ])->save();

        $this->get('/settings/ai/providers')->assertOk()
            ->assertSee('Uji gagal')
            ->assertSee('Provider membatasi laju permintaan.')
            ->assertDontSee('Aktifkan');
    }

    public function test_embedding_revision_displays_tested_dimension_and_activation_requires_it(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'provider.example.test/v1/embeddings' => Http::response([
                'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.5, -0.25, 0.125, 0.75]]],
            ]),
        ]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));

        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload([
            'purpose' => 'embedding',
            'model_id' => 'vendor/embed-model',
        ]))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/test')->assertRedirect();
        $this->assertSame(4, $first->fresh()->tested_embedding_dimensions);

        $this->get('/settings/ai/providers')->assertOk()
            ->assertSee('Dimensi embedding teruji: 4')
            ->assertSee('Aktifkan');

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/activate')->assertRedirect();
        $this->assertSame($first->id, $configuration->fresh()->active_revision_id);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload([
            'purpose' => 'embedding',
            'model_id' => 'vendor/embed-model-2',
        ]))->assertRedirect();
        $second = $configuration->revisions()->latest('revision_number')->firstOrFail();
        $second->forceFill([
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-no-dimensions',
            'test_started_at' => now(),
            'tested_at' => now(),
            'tested_embedding_dimensions' => 0,
        ])->save();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$second->id.'/activate')->assertStatus(422);
        $this->assertSame($first->id, $configuration->fresh()->active_revision_id);
    }

    public function test_activation_is_idempotent_for_older_passed_evidence_and_rejects_disabled_or_interrupted_attempts(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $revision->forceFill([
            'test_state' => 'passed',
            'test_attempt_id' => 'attempt-current',
            'test_started_at' => now()->subSeconds(120),
            'tested_at' => now()->subSeconds(120),
        ])->save();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/activate')->assertRedirect();
        $activateAudits = DB::table('user_activity_logs')->where('action', 'activate')->where('record_id', $configuration->id)->count();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/activate')->assertRedirect();
        $this->assertSame($activateAudits, DB::table('user_activity_logs')->where('action', 'activate')->where('record_id', $configuration->id)->count());
        $this->assertSame($revision->id, $configuration->fresh()->active_revision_id);
        $this->assertNull($configuration->fresh()->previous_known_good_revision_id);

        $revision->forceFill(['enabled' => false])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload());
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/activate')->assertStatus(422);

        $interrupted = $configuration->revisions()->latest('revision_number')->firstOrFail();
        $interrupted->forceFill([
            'test_state' => 'testing',
            'test_attempt_id' => 'attempt-interrupted',
            'test_started_at' => now()->subSeconds(120),
            'tested_at' => null,
        ])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$interrupted->id.'/activate')->assertStatus(422);
        $this->assertSame($revision->id, $configuration->fresh()->active_revision_id);
    }

    public function test_activation_preserves_older_previous_known_good_when_outgoing_active_is_failed(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();
        $first->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-first', 'test_started_at' => now()->subMinutes(5), 'tested_at' => now()->subMinutes(5)])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/activate')->assertRedirect();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload(['model_id' => 'vendor/second-model']))->assertRedirect();
        $second = $configuration->revisions()->latest('revision_number')->firstOrFail();
        $second->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-second', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$second->id.'/activate')->assertRedirect();
        $second->forceFill(['test_state' => 'failed', 'test_failure_category' => 'provider_unavailable'])->save();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload(['model_id' => 'vendor/third-model']))->assertRedirect();
        $third = $configuration->revisions()->latest('revision_number')->firstOrFail();
        $third->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-third', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$third->id.'/activate')->assertRedirect();

        $this->assertSame($third->id, $configuration->fresh()->active_revision_id);
        $this->assertSame($first->id, $configuration->fresh()->previous_known_good_revision_id);
    }

    public function test_restore_rejects_retested_failed_previous_without_changing_pointers(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();
        $first->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-first', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/activate')->assertRedirect();
        $this->assertSame($first->id, $configuration->fresh()->active_revision_id);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->payload(['model_id' => 'vendor/second-model']))->assertRedirect();
        $second = $configuration->revisions()->whereKeyNot($first->id)->firstOrFail();
        $second->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'attempt-second', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$second->id.'/activate')->assertRedirect();
        $this->assertSame($first->id, $configuration->fresh()->previous_known_good_revision_id);

        Http::preventStrayRequests();
        Http::fake(['provider.example.test/v1/chat/completions' => Http::response(['error' => ['message' => 'Service unavailable']], 503)]);
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$first->id.'/test')->assertRedirect();
        $this->assertSame('failed', $first->fresh()->test_state);

        $this->post('/settings/ai/providers/'.$configuration->id.'/restore', [
            'expected_active_revision_id' => $second->id,
            'expected_previous_revision_id' => $first->id,
        ])->assertStatus(422);

        $this->assertSame($second->id, $configuration->fresh()->active_revision_id);
        $this->assertSame($first->id, $configuration->fresh()->previous_known_good_revision_id);
    }

    public function test_guest_and_operator_cannot_drive_lifecycle_actions(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->payload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $testUrl = '/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/test';

        auth()->logout();
        $this->post($testUrl)->assertRedirect('/login');

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'manage_ai' => false]);
        $this->actingAs($operator)->post($testUrl)->assertRedirect('/403');
        $this->actingAs($operator)->post('/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/activate')->assertRedirect('/403');
        $this->actingAs($operator)->post('/settings/ai/providers/'.$configuration->id.'/restore', [
            'expected_active_revision_id' => $revision->id,
            'expected_previous_revision_id' => $revision->id,
        ])->assertRedirect('/403');

        $this->assertSame('untested', $revision->fresh()->test_state);
        $this->assertNull($configuration->fresh()->active_revision_id);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'display_name' => 'Primary AI',
            'purpose' => 'chat',
            'driver' => 'openai-compatible',
            'model_id' => 'vendor/chat-model',
            'base_url' => 'https://provider.example.test/v1',
            'credential' => 'test-secret-value',
            'generation_parameters' => ['temperature' => 0.4, 'max_tokens' => 2048],
            'enabled' => true,
        ], $overrides);
    }
}
