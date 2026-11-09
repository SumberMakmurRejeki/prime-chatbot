<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\User;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\ChatProviderResolver;
use App\Services\AI\GeminiFileSearchConnection;
use App\Services\AI\GeminiFileSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiAdminProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_CREDENTIAL = 'fictional-admin-gemini-key';

    private const GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    protected function setUp(): void
    {
        parent::setUp();

        // Resolver DNS diganti stub agar suite tidak pernah menyentuh jaringan;
        // resolusi koneksi Gemini hanya membangun provider tanpa panggilan HTTP.
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    public function test_admin_gemini_configuration_requires_test_evidence_then_serves_generation_and_file_search(): void
    {
        Http::preventStrayRequests();

        $manager = User::factory()->create(['role' => 'admin', 'is_active' => true, 'manage_ai' => true]);
        $this->actingAs($manager)->post('/settings/ai/providers', [
            'display_name' => 'Gemini Chat',
            'purpose' => 'chat',
            'driver' => 'gemini',
            'model_id' => 'gemini-3.5-flash-lite',
            'base_url' => self::GEMINI_BASE_URL,
            'credential' => self::ACTIVE_CREDENTIAL,
        ])->assertRedirect('/settings/ai/providers')->assertSessionHasNoErrors();

        $configuration = AiProviderConfiguration::query()->sole();
        $revision = $configuration->revisions()->sole();
        $this->assertSame('gemini', $revision->driver);
        $activationUrl = '/settings/ai/providers/'.$configuration->id.'/revisions/'.$revision->id.'/activate';

        // Kontrak uji-dahulu: aktivasi tanpa bukti lulus harus ditolak.
        $this->post($activationUrl)->assertStatus(422);
        $this->assertNull($configuration->fresh()->active_revision_id);

        $revision->forceFill([
            'test_state' => 'passed',
            'test_attempt_id' => 'fictional-admin-attempt',
            'test_started_at' => now(),
            'tested_at' => now(),
        ])->save();

        $this->post($activationUrl)->assertRedirect();
        $this->assertSame($revision->id, $configuration->fresh()->active_revision_id);

        // Credential aktif yang sama harus melayani jawaban (chat provider)
        // dan seluruh operasi File Search melalui satu resolusi.
        $active = app(ChatProviderResolver::class)->resolve();
        $this->assertSame($revision->id, $active->id);
        $this->assertSame('gemini', $active->driver);

        $resolution = app(GeminiFileSearchConnection::class)->resolveWithCredential();
        $this->assertSame($configuration->id, $resolution['configuration_id']);
        $this->assertSame($revision->id, $resolution['revision_id']);
        $this->assertInstanceOf(GeminiFileSearchProvider::class, $resolution['provider']);
        $this->assertSame('gemini', $resolution['provider']->driver());
        $this->assertSame(self::ACTIVE_CREDENTIAL, $resolution['provider']->providerCredentials()['key']);
        Http::assertNothingSent();
    }
}
