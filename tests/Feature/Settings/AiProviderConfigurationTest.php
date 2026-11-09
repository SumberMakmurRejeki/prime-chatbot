<?php

namespace Tests\Feature\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Models\GuestSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class AiProviderConfigurationTest extends TestCase
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

    public function test_only_active_ai_manager_can_view_or_save_provider_configuration(): void
    {
        $this->get('/settings/ai/providers')->assertRedirect('/login');
        $this->actingAs($this->admin)->get('/settings/ai/providers')->assertForbidden();
        $this->actingAs($this->admin)->post('/settings/ai/providers', $this->validPayload())->assertForbidden();
        $this->actingAs($this->operator)->get('/settings/ai/providers')->assertRedirect('/403');
        $this->actingAs($this->operator)->post('/settings/ai/providers', $this->validPayload())->assertRedirect('/403');

        $this->manager->forceFill(['is_active' => false])->save();
        $this->actingAs($this->manager)->get('/settings/ai/providers')->assertForbidden();
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload())->assertForbidden();
        $this->assertDatabaseCount('ai_provider_configurations', 0);
    }

    public function test_established_guest_session_cannot_view_or_save_provider_configuration(): void
    {
        $guestSession = GuestSession::query()->create([
            'guest_name' => 'Guest PRIME',
            'session_id' => 'ai-provider-guest-session',
            'login_at' => now(),
        ]);

        $this->withSession([
            'guest_session_id' => $guestSession->id,
            'guest_name' => $guestSession->guest_name,
        ]);

        $this->get('/settings/ai/providers')->assertRedirect('/403');
        $this->post('/settings/ai/providers', $this->validPayload())->assertRedirect('/403');
        $this->assertDatabaseCount('ai_provider_configurations', 0);
        $this->assertDatabaseCount('ai_provider_configuration_revisions', 0);
    }

    public function test_unauthorized_users_cannot_revise_a_gemini_configuration(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'driver' => 'gemini',
            'display_name' => 'Gemini Guarded',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'credential' => 'fictional-gemini-key',
        ]))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->sole();
        $reviseUrl = '/settings/ai/providers/'.$configuration->id.'/revisions';
        $payload = $this->validPayload([
            'driver' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        $this->actingAs($this->admin)->post($reviseUrl, $payload)->assertForbidden();
        $this->actingAs($this->operator)->post($reviseUrl, $payload)->assertRedirect('/403');
        $this->app['auth']->logout();
        $this->post($reviseUrl, $payload)->assertRedirect('/login');

        $this->assertSame(1, $configuration->revisions()->count());
        $this->assertSame('gemini', $configuration->revisions()->sole()->driver);
    }

    public function test_save_creates_encrypted_untested_revision_without_activation_or_secret_readback(): void
    {
        $secret = 'provider-token-value-that-must-not-leak';
        $payload = $this->validPayload(['credential' => $secret]);
        $logHandler = new TestHandler;
        app('log')->getLogger()->pushHandler($logHandler);

        $response = $this->actingAs($this->manager)->from('/settings/ai/providers')->post('/settings/ai/providers', $payload);
        $response->assertRedirect('/settings/ai/providers');

        $configuration = AiProviderConfiguration::query()->with('revisions')->firstOrFail();
        $revision = $configuration->revisions->sole();
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame('untested', $revision->test_state);
        $this->assertNull($configuration->active_revision_id);
        $this->assertNull($configuration->previous_known_good_revision_id);
        $this->assertSame($secret, $revision->credential);
        $this->assertStringNotContainsString($secret, DB::table('ai_provider_configuration_revisions')->value('credential'));
        $this->assertStringNotContainsString($secret, json_encode($configuration->toArray(), JSON_THROW_ON_ERROR));
        $response->assertSessionHasNoErrors();
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));

        $page = $this->get('/settings/ai/providers');
        $page->assertOk()->assertSee('tersimpan (••••••••)')->assertDontSee($secret);
        $this->assertStringNotContainsString($secret, json_encode($logHandler->getRecords(), JSON_THROW_ON_ERROR));
        app('log')->getLogger()->popHandler();
    }

    public function test_provider_revisions_do_not_leak_across_instances_and_metadata_query_excludes_secret_value(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Chat One',
            'credential' => 'chat-one-secret',
        ]))->assertRedirect();
        $this->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Embedding Two',
            'purpose' => 'embedding',
            'credential' => null,
        ]))->assertRedirect();

        $page = $this->get('/settings/ai/providers')->assertOk()
            ->assertSee('Chat One')->assertSee('Embedding Two')
            ->assertSee('tersimpan (••••••••)')
            ->assertDontSee('chat-one-secret');

        $chat = AiProviderConfiguration::query()->where('display_name', 'Chat One')->firstOrFail();
        $embedding = AiProviderConfiguration::query()->where('display_name', 'Embedding Two')->firstOrFail();
        $this->assertSame('chat-one-secret', $chat->revisions()->firstOrFail()->credential);
        $this->assertNull($embedding->revisions()->firstOrFail()->credential);
        $this->assertStringNotContainsString('chat-one-secret', json_encode($page->getContent(), JSON_THROW_ON_ERROR));
    }

    public function test_revision_edit_reuses_or_replaces_credential_without_mutating_previous_revision(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload(['credential' => 'initial-secret']))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->validPayload([
            'display_name' => 'Revised provider',
            'credential' => '',
            'model_id' => 'new-model-id',
        ]))->assertRedirect();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->validPayload([
            'display_name' => 'Revised provider',
            'credential' => 'replacement-secret',
            'model_id' => 'newer-model-id',
        ]))->assertRedirect();

        $revisions = $configuration->fresh()->revisions()->orderBy('revision_number')->get();
        $this->assertCount(3, $revisions);
        $this->assertSame('initial-secret', $first->fresh()->credential);
        $this->assertSame('initial-secret', $revisions[1]->credential);
        $this->assertSame('replacement-secret', $revisions[2]->credential);
        $this->assertSame('untested', $revisions[1]->test_state);
        $this->assertNull($configuration->fresh()->active_revision_id);
        $this->assertNull($configuration->fresh()->previous_known_good_revision_id);
    }

    public function test_revision_number_is_allocated_under_configuration_lock_and_internal_state_is_not_mass_assignable(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->validPayload([
            'configuration_id' => 999,
            'revision_number' => 999,
            'test_state' => 'tested',
            'active_revision_id' => 999,
            'previous_known_good_revision_id' => 999,
        ]))->assertRedirect();

        $revision = $configuration->fresh()->revisions()->latest('revision_number')->firstOrFail();
        $this->assertSame(2, $revision->revision_number);
        $this->assertSame('untested', $revision->test_state);
        $this->assertSame($configuration->id, $revision->configuration_id);
        $this->assertNull($configuration->fresh()->active_revision_id);
        $this->assertNull($configuration->fresh()->previous_known_good_revision_id);
    }

    public function test_admin_can_create_a_gemini_configuration_with_persisted_gemini_driver(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'driver' => 'gemini',
            'display_name' => 'Gemini Chat',
            'model_id' => 'gemini-3.5-flash-lite',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'credential' => 'fictional-gemini-key',
        ]))->assertRedirect('/settings/ai/providers')->assertSessionHasNoErrors();

        $configuration = AiProviderConfiguration::query()->sole();
        $revision = $configuration->revisions()->sole();

        $this->assertSame('gemini', $revision->driver);
        $this->assertSame('gemini-3.5-flash-lite', $revision->model_id);
        $this->assertSame('fictional-gemini-key', $revision->credential);
        $this->assertSame('untested', $revision->test_state);
        $this->assertNull($configuration->active_revision_id);
    }

    public function test_revising_a_gemini_configuration_keeps_the_driver_and_retains_the_stored_credential(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.5-flash-lite',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'credential' => 'fictional-gemini-key',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $configuration = AiProviderConfiguration::query()->sole();
        $first = $configuration->revisions()->sole();

        $revisionPayload = $this->validPayload([
            'model_id' => 'gemini-3.8-flash',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'credential' => '',
        ]);
        unset($revisionPayload['driver']);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $revisionPayload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $revisions = $configuration->fresh()->revisions()->orderBy('revision_number')->get();
        $this->assertCount(2, $revisions);
        $this->assertSame(2, $revisions[1]->revision_number);
        $this->assertSame('gemini', $revisions[1]->driver);
        $this->assertSame('gemini-3.8-flash', $revisions[1]->model_id);
        $this->assertSame('fictional-gemini-key', $revisions[1]->credential);
        $this->assertSame('gemini', $first->fresh()->driver);
        $this->assertSame('untested', $revisions[1]->test_state);
        $this->assertNull($configuration->fresh()->active_revision_id);
    }

    public function test_requests_without_driver_input_use_the_default_and_keep_the_existing_driver(): void
    {
        $createPayload = $this->validPayload();
        unset($createPayload['driver']);

        $this->actingAs($this->manager)->post('/settings/ai/providers', $createPayload)->assertRedirect()->assertSessionHasNoErrors();
        $configuration = AiProviderConfiguration::query()->sole();
        $this->assertSame('openai-compatible', $configuration->revisions()->sole()->driver);

        $revisionPayload = $this->validPayload(['model_id' => 'newer-model-id']);
        unset($revisionPayload['driver']);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $revisionPayload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['openai-compatible', 'openai-compatible'],
            $configuration->fresh()->revisions()->orderBy('revision_number')->pluck('driver')->all(),
        );
    }

    public function test_revision_cannot_switch_the_configuration_to_another_provider_driver(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'driver' => 'gemini',
            'model_id' => 'gemini-3.5-flash-lite',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'credential' => 'fictional-gemini-key',
        ]))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->sole();

        $this->from('/settings/ai/providers')->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->validPayload([
            'driver' => 'openai-compatible',
        ]))->assertSessionHasErrors('driver');

        $this->assertSame(1, $configuration->revisions()->count());
        $this->assertSame('gemini', $configuration->revisions()->sole()->driver);
    }

    public function test_unsupported_driver_values_are_rejected_server_side_without_creating_any_row(): void
    {
        foreach (['sumopod', 'anthropic', 'GEMINI', 'gemini/file-search'] as $unsupportedDriver) {
            $this->actingAs($this->manager)->from('/settings/ai/providers')->post('/settings/ai/providers', $this->validPayload([
                'driver' => $unsupportedDriver,
                'display_name' => 'Driver ['.$unsupportedDriver.']',
            ]))->assertSessionHasErrors('driver');
        }

        $this->assertDatabaseCount('ai_provider_configurations', 0);
        $this->assertDatabaseCount('ai_provider_configuration_revisions', 0);
    }

    public function test_invalid_provider_input_is_rejected_without_partial_rows_and_secret_is_not_old_input(): void
    {
        $secret = 'secret-must-not-return';
        $response = $this->from('/settings/ai/providers')->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'base_url' => 'file:///etc/passwd',
            'credential' => $secret,
        ]));

        $response->assertSessionHasErrors(['base_url'])
            ->assertSessionMissing('_old_input.credential');
        $this->assertDatabaseCount('ai_provider_configurations', 0);
        $this->assertDatabaseCount('ai_provider_configuration_revisions', 0);
    }

    public function test_base_url_is_required_for_a_new_provider(): void
    {
        $response = $this->actingAs($this->manager)->from('/settings/ai/providers')->post('/settings/ai/providers', $this->validPayload([
            'base_url' => null,
        ]));

        $response->assertSessionHasErrors('base_url');
        $this->assertDatabaseCount('ai_provider_configurations', 0);
        $this->assertDatabaseCount('ai_provider_configuration_revisions', 0);
    }

    public function test_unsafe_or_non_public_base_urls_are_rejected_by_the_ais_transport_policy(): void
    {
        // Alamat privat (mis. 127.0.0.1) ditolak pada saat outbound oleh pinning
        // transport AI-1B2, sedangkan sintaks endpoint ditolak saat penyimpanan.
        foreach (['http://api.example.test/v1', 'https://localhost/v1', 'https://api.example.test:8443/v1', 'https://user:pass@api.example.test/v1'] as $unsafe) {
            $response = $this->actingAs($this->manager)->from('/settings/ai/providers')->post('/settings/ai/providers', $this->validPayload([
                'base_url' => $unsafe,
            ]));

            $response->assertSessionHasErrors('base_url');
        }

        $this->assertDatabaseCount('ai_provider_configurations', 0);
        $this->assertDatabaseCount('ai_provider_configuration_revisions', 0);
    }

    public function test_simplified_form_omissions_have_safe_server_defaults_and_save_stays_untested(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Tanpa parameter',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $configuration = AiProviderConfiguration::query()->where('display_name', 'Tanpa parameter')->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $this->assertSame('openai-compatible', $revision->driver);
        $this->assertTrue($revision->enabled);
        $this->assertNull($revision->generation_parameters);
        $this->assertSame('untested', $revision->test_state);
        $this->assertNull($configuration->active_revision_id);
        $this->assertNull($configuration->previous_known_good_revision_id);
    }

    public function test_both_purpose_values_are_accepted_and_credential_may_be_blank(): void
    {
        foreach ([['chat', 'Chat only'], ['embedding', 'Embedding only']] as [$purpose, $name]) {
            $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
                'display_name' => $name,
                'purpose' => $purpose,
                'credential' => '',
            ]))->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(['chat', 'embedding'], AiProviderConfiguration::query()->orderBy('purpose')->pluck('purpose')->all());
        $this->assertSame(2, AiProviderConfigurationRevision::query()->whereNull('credential')->count());
    }

    public function test_provider_form_validation_feedback_is_visible_and_preserves_the_originating_modal(): void
    {
        $this->withSession([
            'errors' => new MessageBag(['base_url' => ['Base URL ditolak oleh kebijakan endpoint aman.']]),
            '_old_input' => ['_provider_form' => 'create', 'display_name' => 'Draft aman'],
        ])->actingAs($this->manager)->get('/settings/ai/providers')->assertOk()
            ->assertSee('Base URL ditolak oleh kebijakan endpoint aman.')
            ->assertSee('data-provider-error-modal="create"', false)
            ->assertSee('value="Draft aman"', false);
    }

    public function test_provider_page_uses_modal_forms_and_a_compact_list_without_technical_fields(): void
    {
        $page = $this->actingAs($this->manager)->get('/settings/ai/providers')->assertOk();
        $html = (string) $page->getContent();

        $page->assertSee('+ Tambah Provider')
            ->assertSee('Tambah Provider AI')
            ->assertSee('role="dialog"', false)
            ->assertSee('Digunakan untuk')
            ->assertSee('Chatbot PRIME')
            ->assertSee('Pencarian Dokumen (RAG)')
            ->assertSee('Jenis provider')
            ->assertSee('OpenAI-compatible')
            ->assertSee('Gemini (Google AI)')
            ->assertSee('name="driver"', false)
            ->assertSee('Base URL')
            ->assertSee('type="password"', false)
            ->assertSee('Ambil model dari provider')
            ->assertSee('Model ID manual')
            ->assertSee('Belum ada provider AI.')
            ->assertDontSee('Driver / protokol')
            ->assertDontSee('Temperature')
            ->assertDontSee('Max tokens')
            ->assertDontSee('name="enabled"', false);

        // Satu-satunya form simpan harus berada di dalam modal, bukan tampil permanen.
        $modalPos = strpos($html, 'id="ai-provider-create-modal"');
        $formPos = strpos($html, 'action="'.route('settings-ai-providers.store').'"');
        $this->assertNotFalse($modalPos);
        $this->assertNotFalse($formPos);
        $this->assertGreaterThan($modalPos, $formPos);
    }

    public function test_new_configuration_modal_prefills_latest_provider_values_without_rendering_credential(): void
    {
        $secret = 'prefill-secret-must-not-render';
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Provider Prefill',
            'purpose' => 'embedding',
            'model_id' => 'vendor/latest-embed',
            'base_url' => 'https://models.example.test/v1',
            'credential' => $secret,
        ]))->assertRedirect();

        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $response = $this->get('/settings/ai/providers')->assertOk()
            ->assertSee('Buat konfigurasi baru')
            ->assertSee('action="'.route('settings-ai-providers.revise', $configuration).'"', false)
            ->assertSee('value="Provider Prefill"', false)
            ->assertSee('value="embedding" selected', false)
            ->assertSee('value="https://models.example.test/v1"', false)
            ->assertSee('value="vendor/latest-embed"', false)
            ->assertSee('name="driver" value="openai-compatible"', false)
            ->assertSee('Kosongkan untuk menggunakan kembali API Key tersimpan.')
            ->assertDontSee($secret);

        $this->assertStringNotContainsString($secret, (string) $response->getContent());
    }

    public function test_audit_records_allowlisted_provider_metadata_without_credentials(): void
    {
        $secret = 'audit-secret-must-never-persist';
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload(['credential' => $secret]))->assertRedirect();

        $audit = DB::table('user_activity_logs')->where('module_name', 'ai_provider_configuration')->firstOrFail();
        $values = $audit->new_values;
        $this->assertStringContainsString('openai-compatible', $values);
        $this->assertStringContainsString('credential_configured', $values);
        $this->assertStringNotContainsString($secret, $values);
        $this->assertStringNotContainsString($secret, (string) $audit->description);
    }

    public function test_ai_manager_can_delete_one_provider_with_all_owned_revisions_and_safe_audit(): void
    {
        $secret = 'delete-proof-fake-secret';
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Delete Target',
            'credential' => $secret,
        ]))->assertRedirect();
        $target = AiProviderConfiguration::query()->where('display_name', 'Delete Target')->firstOrFail();
        $firstRevision = $target->revisions()->firstOrFail();
        $this->post('/settings/ai/providers/'.$target->id.'/revisions', $this->validPayload([
            'display_name' => 'Delete Target',
            'model_id' => 'vendor/second-model',
            'credential' => '',
        ]))->assertRedirect();
        $secondRevision = $target->revisions()->whereKeyNot($firstRevision->id)->firstOrFail();
        $firstRevision->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'delete-test-1', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $secondRevision->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'delete-test-2', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $target->forceFill([
            'active_revision_id' => $secondRevision->id,
            'previous_known_good_revision_id' => $firstRevision->id,
        ])->save();

        $this->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Preserved Provider',
            'credential' => 'preserved-fake-secret',
        ]))->assertRedirect();
        $other = AiProviderConfiguration::query()->where('display_name', 'Preserved Provider')->firstOrFail();
        $otherSnapshot = $other->fresh()->getAttributes();
        $otherRevisionSnapshot = $other->revisions()->firstOrFail()->getAttributes();
        $ciphertext = DB::table('ai_provider_configuration_revisions')->where('id', $firstRevision->id)->value('credential');

        $this->delete('/settings/ai/providers/'.$target->id)
            ->assertRedirect('/settings/ai/providers')
            ->assertSessionHas('flash_success', 'Provider "Delete Target" berhasil dihapus.');

        $this->assertDatabaseMissing('ai_provider_configurations', ['id' => $target->id]);
        $this->assertDatabaseMissing('ai_provider_configuration_revisions', ['id' => $firstRevision->id]);
        $this->assertDatabaseMissing('ai_provider_configuration_revisions', ['id' => $secondRevision->id]);
        $this->assertSame($otherSnapshot, $other->fresh()->getAttributes());
        $this->assertSame($otherRevisionSnapshot, $other->revisions()->firstOrFail()->getAttributes());
        $this->assertSame(1, DB::table('user_activity_logs')->where('action', 'provider_deleted')->where('record_id', $target->id)->count());

        $audit = DB::table('user_activity_logs')->where('action', 'provider_deleted')->where('record_id', $target->id)->firstOrFail();
        $auditPayload = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Delete Target', $auditPayload);
        $this->assertStringNotContainsString($secret, $auditPayload);
        $this->assertStringNotContainsString((string) $ciphertext, $auditPayload);

        $this->delete('/settings/ai/providers/'.$target->id)->assertNotFound();
        $this->assertSame(1, DB::table('user_activity_logs')->where('action', 'provider_deleted')->where('record_id', $target->id)->count());
    }

    public function test_only_ai_manager_can_delete_a_provider(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();

        $this->actingAs($this->admin)->delete('/settings/ai/providers/'.$configuration->id)->assertForbidden();
        $this->actingAs($this->operator)->delete('/settings/ai/providers/'.$configuration->id)->assertRedirect('/403');
        $this->app['auth']->logout();
        $this->delete('/settings/ai/providers/'.$configuration->id)->assertRedirect('/login');

        $this->assertDatabaseHas('ai_provider_configurations', ['id' => $configuration->id]);
        $this->assertDatabaseHas('ai_provider_configuration_revisions', ['configuration_id' => $configuration->id]);
    }

    public function test_provider_page_exposes_delete_confirmation_readiness_and_fresh_add_form_values(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'display_name' => 'Ready Provider',
        ]))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $revision = $configuration->revisions()->firstOrFail();
        $revision->forceFill(['test_state' => 'passed', 'test_attempt_id' => 'ready-attempt', 'test_started_at' => now(), 'tested_at' => now()])->save();
        $configuration->forceFill(['active_revision_id' => $revision->id])->save();

        $response = $this->actingAs($this->manager)->get('/settings/ai/providers')->assertOk();
        $html = (string) $response->getContent();
        $response->assertSee('Siap digunakan')
            ->assertDontSee('Digunakan PRIME')
            ->assertSee('Hapus provider')
            ->assertSee('Provider &quot;Ready Provider&quot; beserta seluruh riwayat konfigurasinya akan dihapus dari PRIME.', false)
            ->assertSee('action="'.route('settings-ai-providers.destroy', $configuration).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('type="password" name="credential" autocomplete="new-password" value=""', false)
            ->assertSee('name="display_name" required maxlength="120" value=""', false)
            ->assertSee('name="base_url" required maxlength="2048" value=""', false)
            ->assertSee('data-model-search autocomplete="off" value=""', false)
            ->assertSee('name="model_id" required maxlength="255" value=""', false);

        $this->assertStringNotContainsString('data-provider-id="'.$configuration->id.'"', substr($html, strpos($html, 'id="ai-provider-create-modal"')));
    }

    public function test_application_credential_placeholders_are_rejected_without_creating_or_changing_revisions(): void
    {
        foreach (['••••••••', '********', 'Credential tersimpan'] as $placeholder) {
            $response = $this->actingAs($this->manager)
                ->from('/settings/ai/providers')
                ->post('/settings/ai/providers', $this->validPayload(['credential' => $placeholder]));

            $response->assertSessionHasErrors('credential')
                ->assertSessionMissing('_old_input.credential');
        }

        $this->assertDatabaseCount('ai_provider_configurations', 0);
        $this->assertDatabaseCount('ai_provider_configuration_revisions', 0);

        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload([
            'credential' => 'initial-secret',
        ]))->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $initialRevision = $configuration->revisions()->firstOrFail();

        foreach (['••••••••', '********', 'Credential tersimpan'] as $placeholder) {
            $response = $this->from('/settings/ai/providers/'.$configuration->id.'/edit')
                ->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->validPayload([
                    'credential' => $placeholder,
                    'base_url' => null,
                ]));

            $response->assertSessionHasErrors('credential')
                ->assertSessionMissing('_old_input.credential');
            $this->assertStringNotContainsString('initial-secret', json_encode(session()->all(), JSON_THROW_ON_ERROR));
            $this->assertSame(1, $configuration->revisions()->count());
            $this->assertSame('initial-secret', $initialRevision->fresh()->credential);
            $this->assertNull($configuration->fresh()->active_revision_id);
            $this->assertNull($configuration->fresh()->previous_known_good_revision_id);
        }
    }

    public function test_supported_revision_flow_never_rewrites_existing_revision_rows(): void
    {
        $this->actingAs($this->manager)->post('/settings/ai/providers', $this->validPayload())->assertRedirect();
        $configuration = AiProviderConfiguration::query()->firstOrFail();
        $first = $configuration->revisions()->firstOrFail();
        $firstSnapshot = $first->only(['id', 'revision_number', 'driver', 'model_id', 'enabled', 'test_state']);

        $this->post('/settings/ai/providers/'.$configuration->id.'/revisions', $this->validPayload([
            'model_id' => 'newer-model-id',
        ]))->assertRedirect();

        $this->assertSame($firstSnapshot, $configuration->revisions()->firstOrFail()->only([
            'id', 'revision_number', 'driver', 'model_id', 'enabled', 'test_state',
        ]));
        $this->assertSame(1, AiProviderConfigurationRevision::query()->where('model_id', 'vendor/model-id')->count());
        $this->assertSame(1, AiProviderConfigurationRevision::query()->where('model_id', 'newer-model-id')->count());
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'display_name' => 'Primary Chat',
            'driver' => 'openai-compatible',
            'purpose' => 'chat',
            'model_id' => 'vendor/model-id',
            'base_url' => 'https://api.example.test/v1',
            'credential' => null,
        ], $overrides);
    }
}
