<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\GeminiFileSearchConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Providers\GeminiProvider;
use RuntimeException;
use Tests\TestCase;

class GeminiFileSearchConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_CREDENTIAL = 'fictional-prime-gemini-key';

    private const FALLBACK_CREDENTIAL = 'fictional-env-fallback-key';

    private const GEMINI_UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta';

    protected function setUp(): void
    {
        parent::setUp();
        // Gateway File/Store yang dipasang koneksi memakai pin DNS PRIME;
        // resolver diuji diganti agar suite tidak menyentuh DNS jaringan nyata.
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    public function test_active_gemini_revision_builds_sdk_provider_and_authenticates_file_upload(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/test-upload']], 200)]);
        config(['ai.providers.gemini.key' => self::FALLBACK_CREDENTIAL]);
        $revision = $this->activeRevision();

        $provider = app(GeminiFileSearchConnection::class)->resolve();

        $this->assertInstanceOf(GeminiProvider::class, $provider);
        $this->assertInstanceOf(FileProvider::class, $provider);
        $this->assertInstanceOf(StoreProvider::class, $provider);
        $this->assertInstanceOf(SupportsFileSearch::class, $provider);
        $this->assertSame('gemini', $provider->driver());
        $this->assertSame(self::ACTIVE_CREDENTIAL, $provider->providerCredentials()['key']);
        $this->assertSame($revision->model_id, $provider->defaultTextModel());
        $storedFile = $provider->putFile((new Base64Document(base64_encode('fictional document'), 'application/pdf'))->as('fictional.pdf'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::GEMINI_UPLOAD_URL.'/files'
            && $request->header('x-goog-api-key') === [self::ACTIVE_CREDENTIAL]);
        $this->assertSame('files/test-upload', $storedFile->id);
    }

    public function test_missing_active_revision_fails_without_environment_fallback_or_secret_disclosure(): void
    {
        Http::preventStrayRequests();
        config(['ai.providers.gemini.key' => self::FALLBACK_CREDENTIAL]);

        $exception = $this->captureConnectionFailure();

        $this->assertSame('No unique eligible active chat provider is available.', $exception->getMessage());
        $this->assertStringNotContainsString(self::FALLBACK_CREDENTIAL, $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_inactive_provider_revision_is_not_used(): void
    {
        Http::preventStrayRequests();
        $this->activeRevision(['enabled' => false]);

        $exception = $this->captureConnectionFailure();

        $this->assertSame('No unique eligible active chat provider is available.', $exception->getMessage());
        $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_non_gemini_active_provider_fails_without_fallback(): void
    {
        Http::preventStrayRequests();
        config(['ai.providers.gemini.key' => self::FALLBACK_CREDENTIAL]);
        $this->activeRevision(['driver' => 'openai']);

        $exception = $this->captureConnectionFailure();

        $this->assertSame('The active chat provider does not support Gemini File Search.', $exception->getMessage());
        $this->assertStringNotContainsString(self::FALLBACK_CREDENTIAL, $exception->getMessage());
        Http::assertNothingSent();
        $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, $exception->getMessage());
    }

    public function test_missing_credential_is_ineligible_even_when_environment_key_exists(): void
    {
        Http::preventStrayRequests();
        config(['ai.providers.gemini.key' => self::FALLBACK_CREDENTIAL]);
        $this->activeRevision(['credential' => null]);

        $exception = $this->captureConnectionFailure();

        $this->assertSame('No unique eligible active chat provider is available.', $exception->getMessage());
        $this->assertStringNotContainsString(self::FALLBACK_CREDENTIAL, $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_unsupported_driver_configuration_fails_closed(): void
    {
        Http::preventStrayRequests();
        $this->activeRevision(['driver' => 'unsupported-driver']);

        $exception = $this->captureConnectionFailure();

        $this->assertSame('No unique eligible active chat provider is available.', $exception->getMessage());
        Http::assertNothingSent();
        $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, $exception->getMessage());
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

    private function captureConnectionFailure(): RuntimeException
    {
        try {
            app(GeminiFileSearchConnection::class)->resolve();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        $this->fail('Expected Gemini connection resolution to fail closed.');
    }
}
