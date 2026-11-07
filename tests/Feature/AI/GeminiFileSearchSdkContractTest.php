<?php

namespace Tests\Feature\AI;

use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Tests\TestCase;
use UnhandledMatchError;

/**
 * Kontrak Gemini File Search pada laravel/ai v1.2.0, dibuktikan lewat payload
 * permintaan dan parsing respons yang dibekukan di test ini (tanpa panggilan jaringan).
 */
class GeminiFileSearchSdkContractTest extends TestCase
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function test_file_search_store_and_metadata_filter_reach_the_gemini_interaction_payload(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Interval pemeriksaan 500 jam.']]],
            ],
        ])]);

        $provider = $this->geminiProvider();

        $step = $provider->textGateway()->generateTextStep(
            $provider,
            'gemini-3.8-flash',
            'Jawab hanya dari dokumen terbit PRIME.',
            [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
            [new FileSearch(['knowledge-published'], fn ($query) => $query
                ->where('document_code', 'PM-001')
                ->where('version', 3))],
            null,
            null,
            30,
            new StepContext,
        );

        Http::assertSent(function (Request $request): bool {
            $tools = $request['tools'];

            return $request->url() === self::BASE_URL.'/interactions'
                && $request->header('x-goog-api-key') === ['test-api-key']
                && $request['model'] === 'gemini-3.8-flash'
                && $request['store'] === false
                && $request['system_instruction'] === 'Jawab hanya dari dokumen terbit PRIME.'
                && count($tools) === 1
                && $tools[0]['type'] === 'file_search'
                && $tools[0]['file_search_store_names'] === ['knowledge-published']
                && $tools[0]['metadata_filter'] === 'document_code="PM-001" AND version=3';
        });

        $this->assertSame('Interval pemeriksaan 500 jam.', $step->text);
        $this->assertSame(FinishReason::Stop, $step->finishReason);
        $this->assertSame('gemini', $step->meta->provider);
        $this->assertSame('gemini-3.8-flash', $step->meta->model);
    }

    public function test_metadata_filter_renders_membership_as_a_disjunctive_group(): void
    {
        $options = $this->geminiProvider()->fileSearchToolOptions(new FileSearch(
            ['knowledge-published'],
            fn ($query) => $query
                ->whereNot('version', 2)
                ->whereIn('document_code', ['PM-001', 'PM-002']),
        ));

        $this->assertSame([
            'file_search_store_names' => ['knowledge-published'],
            'metadata_filter' => 'version!=2 AND (document_code="PM-001" OR document_code="PM-002")',
        ], $options);
    }

    public function test_metadata_filter_rejects_the_not_in_operator_before_any_request(): void
    {
        Http::preventStrayRequests();

        $search = new FileSearch(
            ['knowledge-published'],
            fn ($query) => $query->whereNotIn('document_code', ['PM-DRAFT']),
        );

        try {
            $this->geminiProvider()->fileSearchToolOptions($search);
            $this->fail('Negasi "not in" harus gagal, bukan menghasilkan filter yang salah.');
        } catch (UnhandledMatchError $exception) {
            $this->assertStringContainsString("'nin'", $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_file_search_grounding_data_survives_as_a_provider_tool_call_while_only_url_citations_become_citations(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [
                [
                    'type' => 'file_search_call',
                    'id' => 'fs-1',
                    'query' => 'interval pemeriksaan mesin',
                    'results' => [[
                        'document' => 'fileSearchStores/store-1/documents/doc-9',
                        'score' => 0.87,
                    ]],
                ],
                [
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Interval pemeriksaan 500 jam.',
                        'annotations' => [
                            [
                                'type' => 'url_citation',
                                'url' => 'https://example.test/knowledge/pm-001',
                                'title' => 'PM-001',
                            ],
                            [
                                'type' => 'file_citation',
                                'file_name' => 'PM-001-sop-pemeliharaan.pdf',
                                'document_uri' => 'fileSearchStores/store-1',
                                'source' => 'interval pemeriksaan 500 jam',
                                'page_number' => 12,
                                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
                            ],
                            [
                                'type' => 'grounding_citation',
                                'document' => 'fileSearchStores/store-1/documents/doc-9',
                            ],
                        ],
                    ]],
                ],
            ],
        ])]);

        $provider = $this->geminiProvider();

        $step = $provider->textGateway()->generateTextStep(
            $provider,
            'gemini-3.8-flash',
            null,
            [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
            [new FileSearch(['knowledge-published'])],
            null,
            null,
            30,
            new StepContext,
        );

        $this->assertCount(1, $step->providerToolCalls);
        $this->assertSame('file_search', $step->providerToolCalls[0]->type);
        $this->assertSame('fs-1', $step->providerToolCalls[0]->id);
        $this->assertSame('fileSearchStores/store-1/documents/doc-9', $step->providerToolCalls[0]->data['results'][0]['document']);

        $this->assertCount(1, $step->meta->citations);
        $this->assertSame('https://example.test/knowledge/pm-001', $step->meta->citations[0]->url);
        $this->assertSame('PM-001', $step->meta->citations[0]->title);
        $this->assertSame([
            'type' => 'file_citation',
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => 'fileSearchStores/store-1',
            'source' => 'interval pemeriksaan 500 jam',
            'page_number' => 12,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ], $step->raw->json('steps.1.content.0.annotations.1'));
    }

    public function test_missing_and_malformed_file_citations_are_not_mapped_to_sdk_citations(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Jawaban tanpa sumber terpetakan.',
                    'annotations' => [
                        ['type' => 'file_citation'],
                        ['type' => 'file_citation', 'file_name' => ['unexpected' => 'shape']],
                        'bukti-rusak',
                        null,
                    ],
                ]],
            ]],
        ])]);

        $provider = $this->geminiProvider();

        $step = $provider->textGateway()->generateTextStep(
            $provider,
            'gemini-3.8-flash',
            null,
            [new UserMessage('Tanya dokumen')],
            [new FileSearch(['knowledge-published'])],
            null,
            null,
            30,
            new StepContext,
        );

        $this->assertSame([], $step->meta->citations->all());
        $this->assertSame([
            ['type' => 'file_citation'],
            ['type' => 'file_citation', 'file_name' => ['unexpected' => 'shape']],
            'bukti-rusak',
            null,
        ], $step->raw->json('steps.0.content.0.annotations'));
    }

    public function test_file_citation_for_unknown_or_unpublished_document_is_not_authorized_by_the_sdk(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Jawaban bersumber dari dokumen eksternal.',
                    'annotations' => [[
                        'type' => 'file_citation',
                        'file_name' => 'unregistered-draft.pdf',
                        'document_uri' => 'fileSearchStores/other-store',
                        'page_number' => 4,
                    ]],
                ]],
            ]],
        ])]);

        $provider = $this->geminiProvider();

        $step = $provider->textGateway()->generateTextStep(
            $provider,
            'gemini-3.8-flash',
            null,
            [new UserMessage('Tanya dokumen')],
            [new FileSearch(['knowledge-published'])],
            null,
            null,
            30,
            new StepContext,
        );

        $this->assertSame([], $step->meta->citations->all());
        $this->assertSame(
            'fileSearchStores/other-store',
            $step->raw->json('steps.0.content.0.annotations.0.document_uri'),
        );
    }

    public function test_store_creation_and_document_import_send_gemini_file_search_store_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::BASE_URL.'/fileSearchStores' => Http::response(['name' => 'fileSearchStores/store-1']),
            self::BASE_URL.'/fileSearchStores/store-1' => Http::response([
                'name' => 'fileSearchStores/store-1',
                'displayName' => 'PRIME Knowledge',
                'activeDocumentsCount' => 4,
                'pendingDocumentsCount' => 1,
                'failedDocumentsCount' => 2,
            ]),
            self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
                'name' => 'operations/op-1',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/store-1/documents/doc-9'],
            ]),
        ]);

        $provider = $this->geminiProvider();
        $store = $provider->storeGateway()->createStore($provider, 'PRIME Knowledge');

        $documentId = $provider->storeGateway()->addFile($provider, $store->id, 'abc123', [
            'document_code' => 'PM-001',
            'version' => 3,
            'roles' => ['operator', 'admin'],
        ]);

        $this->assertSame('fileSearchStores/store-1', $store->id);
        $this->assertSame('PRIME Knowledge', $store->name);
        $this->assertSame(['completed' => 4, 'pending' => 1, 'failed' => 2], $store->fileCounts->toArray());
        $this->assertTrue($store->ready);
        $this->assertSame('doc-9', $documentId);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/fileSearchStores'
            && $request['displayName'] === 'PRIME Knowledge');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/fileSearchStores/store-1:importFile'
            && $request['fileName'] === 'files/abc123'
            && $request['customMetadata'] === [
                ['key' => 'document_code', 'stringValue' => 'PM-001'],
                ['key' => 'version', 'numericValue' => 3],
                ['key' => 'roles', 'stringListValue' => ['values' => ['operator', 'admin']]],
            ]);
    }

    public function test_document_removal_targets_the_forced_document_resource_path(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1/documents/doc-9*' => Http::response([])]);

        $provider = $this->geminiProvider();

        $removed = $provider->storeGateway()->removeFile($provider, 'store-1', 'doc-9');

        $this->assertTrue($removed);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === self::BASE_URL.'/fileSearchStores/store-1/documents/doc-9?force=true');
    }

    public function test_failed_import_operation_surfaces_the_gemini_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'operations/op-2',
            'done' => true,
            'error' => ['code' => 3, 'message' => 'Store is not writable.'],
        ])]);

        $provider = $this->geminiProvider();

        try {
            $provider->storeGateway()->addFile($provider, 'store-1', 'abc123');
            $this->fail('Operasi impor yang gagal harus melempar AiException.');
        } catch (AiException $exception) {
            $this->assertSame('Gemini Error: [3] Store is not writable.', $exception->getMessage());
        }
    }

    public function test_import_operation_without_a_document_name_is_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
            'name' => 'operations/op-4',
            'done' => true,
            'response' => [],
        ])]);

        $provider = $this->geminiProvider();

        try {
            $provider->storeGateway()->addFile($provider, 'store-1', 'abc123');
            $this->fail('Operasi impor tanpa nama dokumen harus melempar AiException.');
        } catch (AiException $exception) {
            $this->assertSame(
                'Gemini Error: [invalid_response] File import completed without a document name.',
                $exception->getMessage(),
            );
        }
    }

    public function test_pending_import_operation_is_polled_until_the_document_name_arrives(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::BASE_URL.'/fileSearchStores/store-1:importFile' => Http::response([
                'name' => 'operations/op-3',
                'done' => false,
            ]),
            self::BASE_URL.'/operations/op-3' => Http::response([
                'name' => 'operations/op-3',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/store-1/documents/doc-7'],
            ]),
        ]);

        $provider = $this->geminiProvider();

        $documentId = $provider->storeGateway()->addFile($provider, 'store-1', 'abc123');

        $this->assertSame('doc-7', $documentId);
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(2);
    }

    public function test_provider_without_file_search_support_rejects_the_tool_before_sending(): void
    {
        Http::preventStrayRequests();

        $provider = new OpenAiCompatibleProvider([
            'driver' => 'openai-compatible',
            'name' => 'openai-compatible',
            'key' => 'compatible-key',
            'url' => 'https://compatible.example.test/v1/',
        ], new Dispatcher);

        try {
            $provider->textGateway()->generateTextStep(
                $provider,
                'local-chat-model',
                null,
                [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
                [new FileSearch(['knowledge-published'])],
                null,
                null,
                30,
                new StepContext,
            );
            $this->fail('Provider tanpa dukungan File Search harus menolak tool tersebut.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'OpenAiCompatible does not support [FileSearch] provider tools.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    private function geminiProvider(): GeminiProvider
    {
        $events = new Dispatcher;

        return new GeminiProvider(
            new GeminiGateway($events),
            [
                'driver' => 'gemini',
                'name' => 'gemini',
                'key' => 'test-api-key',
                'url' => self::BASE_URL.'/',
            ],
            $events,
        );
    }
}
