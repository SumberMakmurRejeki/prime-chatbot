<?php

namespace Tests\Feature\AI;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\BoundedResponseSink;
use App\Services\AI\GeminiDocumentMetadata;
use App\Services\AI\GeminiFileSearchConnection;
use App\Services\AI\GeminiFileSearchOperations;
use App\Services\AI\GeminiIndexingState;
use App\Services\AI\ProbeTransportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

/**
 * Operasi unggah/impor/status Gemini File Search dengan kredensial revisi
 * aktif, transport PRIME, dan pemeriksaan indeksasi satu kali.
 */
class GeminiFileSearchOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_CREDENTIAL = 'fictional-prime-gemini-key';

    private const ENV_FALLBACK_CREDENTIAL = 'fictional-env-fallback-key';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AiEndpointPinner::class, new AiEndpointPinner(static fn (string $host): array => ['93.184.216.34']));
    }

    public function test_upload_pdf_uses_active_credential_and_returns_remote_identity(): void
    {
        $this->activeRevision();
        config(['ai.providers.gemini.key' => self::ENV_FALLBACK_CREDENTIAL]);
        Http::preventStrayRequests();
        Http::fake([self::UPLOAD_URL.'/files' => Http::response(['file' => ['name' => 'files/remote-1']])]);
        $path = $this->pdfFixture();

        try {
            $fileId = app(GeminiFileSearchOperations::class)->uploadPdf($path);
        } finally {
            unlink($path);
        }

        $this->assertSame('files/remote-1', $fileId);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::UPLOAD_URL.'/files'
            && $request->header('x-goog-api-key') === [self::ACTIVE_CREDENTIAL]
            && str_contains($request->body(), '%PDF-'));
        Http::assertSentCount(1);
    }

    public function test_upload_rejects_non_pdf_without_network_access(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        $path = tempnam(sys_get_temp_dir(), 'prime-gemini-').'.pdf';
        file_put_contents($path, 'not a pdf');

        try {
            app(GeminiFileSearchOperations::class)->uploadPdf($path);
            $this->fail('Non-PDF sources must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini upload requires a readable local PDF within the allowed size limit.', $exception->getMessage());
        } finally {
            unlink($path);
        }

        Http::assertNothingSent();
    }

    public function test_uncertain_upload_is_not_retried(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::UPLOAD_URL.'/files' => Http::failedConnection()]);
        $path = $this->pdfFixture();

        try {
            app(GeminiFileSearchOperations::class)->uploadPdf($path);
            $this->fail('Unknown upload outcome must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini PDF upload outcome is unknown and was not retried.', $exception->getMessage());
        } finally {
            unlink($path);
        }

        Http::assertSentCount(1);
    }

    public function test_import_preserves_numeric_looking_version_as_string_and_reports_pending(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-1',
            'done' => false,
        ])]);

        $report = app(GeminiFileSearchOperations::class)->importFile(
            'store-1',
            'files/file-1',
            new GeminiDocumentMetadata('PUMP-02', '1.10', 'g-import-1'),
        );

        $this->assertSame(GeminiIndexingState::Pending, $report->state);
        $this->assertSame('fileSearchStores/store-1/operations/import-1', $report->operationName);
        $this->assertNull($report->documentName);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/fileSearchStores/store-1:importFile'
            && $request['fileName'] === 'files/file-1'
            && $request['customMetadata'] === [
                ['key' => 'document_code', 'stringValue' => 'PUMP-02'],
                ['key' => 'version', 'stringValue' => '1.10'],
                ['key' => 'import_identity', 'stringValue' => 'g-import-1'],
            ]);
        Http::assertSentCount(1);
    }

    public function test_import_reports_completed_indexing_with_remote_document_identity(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-2',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/store-1/documents/doc-9'],
        ])]);

        $report = app(GeminiFileSearchOperations::class)->importFile(
            'store-1',
            'file-1',
            new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-2'),
        );

        $this->assertSame(GeminiIndexingState::Completed, $report->state);
        $this->assertSame('fileSearchStores/store-1/documents/doc-9', $report->documentName);
        Http::assertSentCount(1);
    }

    public function test_status_reports_failure_without_exposing_provider_message(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/operations/import-3' => Http::response([
            'name' => 'operations/import-3',
            'done' => true,
            'error' => ['code' => 3, 'message' => 'secret provider detail'],
        ])]);

        $report = app(GeminiFileSearchOperations::class)->inspectIndexing('operations/import-3');

        $this->assertSame(GeminiIndexingState::Failed, $report->state);
        $this->assertNull($report->documentName);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::BASE_URL.'/operations/import-3'
            && $request->header('x-goog-api-key') === [self::ACTIVE_CREDENTIAL]);
        Http::assertSentCount(1);
    }

    public function test_malformed_operation_response_fails_without_exposing_payload(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => ['invalid'],
            'fictional-secret' => 'internal provider detail',
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('store-1', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-3'));
            $this->fail('Malformed import response must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('internal provider detail', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }
    }

    public function test_completed_import_without_document_identity_fails_closed(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-4',
            'done' => true,
            'response' => [],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('store-1', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-4'));
            $this->fail('Completed import without a document identity must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('file-1', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_import_rejects_completed_document_from_another_store(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/prime-store-a:importFile' => Http::response([
            'name' => 'fileSearchStores/prime-store-a/operations/import-5',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/prime-store-b/documents/doc-x'],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('prime-store-a', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-5'));
            $this->fail('Completed import must not accept a document from another store.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
            $this->assertStringNotContainsString('prime-store-b', $exception->getMessage());
            $this->assertStringNotContainsString('doc-x', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_import_rejects_operation_resource_scoped_to_another_store(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/prime-store-a:importFile' => Http::response([
            'name' => 'fileSearchStores/prime-store-b/operations/import-6',
            'done' => false,
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('prime-store-a', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-6'));
            $this->fail('Import operation scoped to another store must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
            $this->assertStringNotContainsString('prime-store-b', $exception->getMessage());
        }
    }

    public function test_completed_import_normalizes_relative_document_identity(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-7',
            'done' => true,
            // Google dapat mengembalikan identifier dokumen saja tanpa prefix
            // store; bentuk ini tetap sah selama store induknya diketahui.
            'response' => ['documentName' => 'doc-relative-7'],
        ])]);

        $report = app(GeminiFileSearchOperations::class)->importFile(
            'store-1',
            'file-1',
            new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-7'),
        );

        $this->assertSame(GeminiIndexingState::Completed, $report->state);
        $this->assertSame('fileSearchStores/store-1/documents/doc-relative-7', $report->documentName);
        Http::assertSentCount(1);
    }

    public function test_completed_document_with_malformed_resource_fails_closed(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-7',
            'done' => true,
            'response' => ['documentName' => 'documents/doc-x'],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('store-1', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-7'));
            $this->fail('Malformed completed-document identity must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
            $this->assertStringNotContainsString('doc-x', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_completed_document_with_empty_resource_identifier_fails_closed(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-14',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/store-1/documents/'],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('store-1', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-14'));
            $this->fail('Empty document identifier must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }

        Http::assertSentCount(1);
    }

    public function test_polling_accepts_completed_document_in_the_operations_store(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1/operations/import-8' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-8',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/store-1/documents/doc-9'],
        ])]);

        $report = app(GeminiFileSearchOperations::class)->inspectIndexing('fileSearchStores/store-1/operations/import-8');

        $this->assertSame(GeminiIndexingState::Completed, $report->state);
        $this->assertSame('fileSearchStores/store-1/documents/doc-9', $report->documentName);
    }

    public function test_polling_normalizes_relative_document_identity_in_the_operations_store(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1/operations/import-15' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-15',
            'done' => true,
            'response' => ['documentName' => 'doc-relative-15'],
        ])]);

        $report = app(GeminiFileSearchOperations::class)->inspectIndexing('fileSearchStores/store-1/operations/import-15');

        $this->assertSame(GeminiIndexingState::Completed, $report->state);
        $this->assertSame('fileSearchStores/store-1/documents/doc-relative-15', $report->documentName);
    }

    public function test_polling_rejects_relative_document_identity_without_provable_store_scope(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/operations/import-16' => Http::response([
            'name' => 'operations/import-16',
            'done' => true,
            'response' => ['documentName' => 'doc-relative-16'],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->inspectIndexing('operations/import-16');
            $this->fail('A relative document identity needs a provable operation store scope.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search indexing status could not be read.', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }
    }

    public function test_polling_rejects_completed_document_from_another_store(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1/operations/import-9' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/import-9',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/store-2/documents/doc-y'],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->inspectIndexing('fileSearchStores/store-1/operations/import-9');
            $this->fail('Polling must not accept a document from another store.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search indexing status could not be read.', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }
    }

    public function test_polling_rejects_completed_document_without_provable_store_scope(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/operations/import-10' => Http::response([
            'name' => 'operations/import-10',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/store-1/documents/doc-10'],
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->inspectIndexing('operations/import-10');
            $this->fail('A completed document needs a provable operation store scope.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search indexing status could not be read.', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }
    }

    public function test_polling_rejects_unrelated_operation_identifier(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();

        try {
            app(GeminiFileSearchOperations::class)->inspectIndexing('fileSearchStores/store-1/uploads/import-11');
            $this->fail('Unrelated operation identifier must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search indexing status could not be read.', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
            $this->assertSame('Gemini File Search operation identifier is invalid.', $exception->getPrevious()->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_polling_rejects_response_with_missing_operation_identity(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1/operations/import-12' => Http::response([
            'done' => false,
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->inspectIndexing('fileSearchStores/store-1/operations/import-12');
            $this->fail('Operation response without an identity must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search indexing status could not be read.', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }
    }

    public function test_polling_rejects_response_for_different_operation(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1/operations/import-13' => Http::response([
            'name' => 'fileSearchStores/store-1/operations/another-operation',
            'done' => false,
        ])]);

        try {
            app(GeminiFileSearchOperations::class)->inspectIndexing('fileSearchStores/store-1/operations/import-13');
            $this->fail('Status response must identify the requested operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search indexing status could not be read.', $exception->getMessage());
            $this->assertInstanceOf(AiException::class, $exception->getPrevious());
        }
    }

    public function test_upload_authentication_failure_is_reported_safely(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::UPLOAD_URL.'/files' => Http::response([
            'error' => ['message' => 'fictional credential is invalid'],
        ], 401)]);
        $path = $this->pdfFixture();

        try {
            app(GeminiFileSearchOperations::class)->uploadPdf($path);
            $this->fail('Authentication failure must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini PDF upload failed.', $exception->getMessage());
            $this->assertStringNotContainsString(self::ACTIVE_CREDENTIAL, $exception->getMessage());
        } finally {
            unlink($path);
        }

        Http::assertSentCount(1);
    }

    public function test_rate_limited_import_is_not_retried(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'error' => ['message' => 'synthetic quota'],
        ], 429)]);

        try {
            app(GeminiFileSearchOperations::class)->importFile('store-1', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-8'));
            $this->fail('Rate limited import must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini File Search import request failed.', $exception->getMessage());
            $this->assertInstanceOf(RateLimitedException::class, $exception->getPrevious());
        }

        Http::assertSentCount(1);
    }

    public function test_unsafe_custom_endpoint_is_rejected_before_any_request(): void
    {
        $this->activeRevision(['base_url' => 'http://127.0.0.1/v1beta']);
        Http::preventStrayRequests();

        try {
            app(GeminiFileSearchOperations::class)->importFile('store-1', 'file-1', new GeminiDocumentMetadata('FNC-01', '2.0', 'g-import-9'));
            $this->fail('Unsafe endpoint must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The active Gemini provider endpoint is not allowed.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_file_and_store_gateways_apply_bounded_transport_options(): void
    {
        $this->activeRevision();
        Http::preventStrayRequests();
        $provider = app(GeminiFileSearchConnection::class)->resolve();

        foreach ([$provider->fileGateway(), $provider->storeGateway()] as $gateway) {
            $options = (new \ReflectionMethod($gateway, 'client'))->invoke($gateway, $provider)->getOptions();

            $this->assertSame(ProbeTransportPolicy::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
            $this->assertSame(ProbeTransportPolicy::TOTAL_TIMEOUT_SECONDS, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(['generativelanguage.googleapis.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertInstanceOf(BoundedResponseSink::class, $options['sink']);
            $this->assertSame(ProbeTransportPolicy::MAX_RESPONSE_BODY_BYTES, $this->sinkLimit($options['sink']));
        }
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

    private function sinkLimit(object $sink): int
    {
        $property = new \ReflectionProperty($sink, 'limit');
        $property->setAccessible(true);

        return $property->getValue($sink);
    }

    private function pdfFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prime-gemini-').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return $path;
    }
}
