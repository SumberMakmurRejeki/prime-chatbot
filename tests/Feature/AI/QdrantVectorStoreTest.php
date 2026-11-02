<?php

namespace Tests\Feature\AI;

use App\Services\AI\VectorStore\DistanceMetric;
use App\Services\AI\VectorStore\Qdrant\QdrantConfig;
use App\Services\AI\VectorStore\Qdrant\QdrantTransport;
use App\Services\AI\VectorStore\Qdrant\QdrantVectorStore;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorChunk;
use App\Services\AI\VectorStore\VectorSearchResult;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use App\Services\AI\VectorStore\VectorStoreError;
use App\Services\AI\VectorStore\VectorStoreException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Kontrak dan transport adapter Qdrant PRIME tanpa Qdrant nyata.
 *
 * Semua test memakai Http::fake + Http::preventStrayRequests sehingga tidak ada
 * permintaan keluar nyata dan tidak ada database yang disentuh.
 */
class QdrantVectorStoreTest extends TestCase
{
    private const ENDPOINT = 'http://127.0.0.1:6333';

    private const COLLECTION = 'prime_knowledge';

    private const READYZ_URL = self::ENDPOINT.'/readyz';

    private const COLLECTION_URL = self::ENDPOINT.'/collections/'.self::COLLECTION;

    private const UPSERT_URL = self::COLLECTION_URL.'/points?wait=true';

    private const QUERY_URL = self::COLLECTION_URL.'/points/query';

    private const DELETE_URL = self::COLLECTION_URL.'/points/delete?wait=true';

    private const PAYLOAD_URL = self::COLLECTION_URL.'/points/payload?wait=true';

    private const VECTOR = [0.1, 0.2, 0.3, 0.4];

    protected function setUp(): void
    {
        parent::setUp();

        // Tidak ada permintaan nyata ke Qdrant: apa pun di luar fake gagal.
        Http::preventStrayRequests();
    }

    public function test_api_key_is_sent_only_as_a_header(): void
    {
        Http::fake([self::READYZ_URL => Http::response('all shards are ready', 200)]);

        $this->assertTrue($this->store($this->config(apiKey: 'prime-secret-key'))->isReady());

        [$request] = $this->lastRequest();

        $this->assertSame(self::READYZ_URL, $request->url());
        $this->assertSame('GET', $request->method());
        $this->assertSame('prime-secret-key', $request->header('api-key')[0]);
    }

    public function test_api_key_header_is_absent_when_not_configured(): void
    {
        Http::fake([self::READYZ_URL => Http::response('all shards are ready', 200)]);

        $this->assertTrue($this->store()->isReady());

        [$request] = $this->lastRequest();

        $this->assertFalse($request->hasHeader('api-key'));
    }

    public function test_endpoint_with_embedded_credentials_is_rejected_without_leaking_the_secret(): void
    {
        foreach (['http://user:pass@127.0.0.1:6333', 'http://prime-secret-key@127.0.0.1:6333'] as $url) {
            $exception = $this->expectVectorStoreError(
                fn () => $this->config(url: $url),
                VectorStoreError::Configuration,
            );

            $this->assertStringNotContainsString('prime-secret-key', $exception->getMessage());
            $this->assertStringNotContainsString('pass', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_malformed_endpoints_and_collection_names_are_rejected(): void
    {
        $invalidUrls = [
            'not-a-url',
            'ftp://127.0.0.1:6333',
            'http://127.0.0.1:6333?api_key=prime-secret-key',
            'http://127.0.0.1:6333#fragment',
            'http://127.0.0.1:6333/qdrant',
            'http://127.0.0.1:6333 /x',
        ];

        foreach ($invalidUrls as $url) {
            $this->expectVectorStoreError(fn () => $this->config(url: $url), VectorStoreError::Configuration);
        }

        foreach (['', 'bad/name', 'bad name'] as $collection) {
            $this->expectVectorStoreError(fn () => $this->config(collection: $collection), VectorStoreError::Configuration);
        }

        foreach ([0, -1] as $timeout) {
            $this->expectVectorStoreError(
                fn () => $this->config(connectTimeout: $timeout),
                VectorStoreError::Configuration,
            );
            $this->expectVectorStoreError(
                fn () => $this->config(requestTimeout: $timeout),
                VectorStoreError::Configuration,
            );
        }

        Http::assertNothingSent();
    }

    public function test_transport_applies_explicit_timeouts_and_disables_redirects(): void
    {
        $options = (new QdrantTransport($this->app->make(Factory::class), $this->config()))
            ->client()
            ->getOptions();

        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(20, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_redirect_response_is_never_followed(): void
    {
        Http::fake([self::READYZ_URL => Http::response('', 302, ['Location' => 'http://elsewhere.test/readyz'])]);

        $this->assertFalse($this->store()->isReady());

        // Hanya satu permintaan: redirect tidak diikuti ke host lain.
        Http::assertSentCount(1);
    }

    public function test_readiness_requires_an_actual_success_status(): void
    {
        Http::fake([self::READYZ_URL => Http::sequence()
            ->push('all shards are ready', 200)
            ->push('all shards are ready, readiness check passed', 503)
            ->push('passed', 302)
            ->push('connection is unavailable', 503)]);

        $store = $this->store();

        $this->assertTrue($store->isReady());
        $this->assertFalse($store->isReady());
        $this->assertFalse($store->isReady());
        $this->assertFalse($store->isReady());
    }

    public function test_readiness_connection_failure_is_never_ready(): void
    {
        Http::fake([self::READYZ_URL => Http::failedConnection()]);

        $this->assertFalse($this->store()->isReady());
    }

    public function test_connection_failure_is_mapped_to_a_safe_exception(): void
    {
        Http::fake([self::UPSERT_URL => Http::failedConnection()]);

        $exception = $this->expectVectorStoreError(
            fn () => $this->store()->upsert($this->space(), 'doc-1', 'v1', [$this->chunk()]),
            VectorStoreError::Connection,
        );

        $this->assertStringNotContainsString(self::ENDPOINT, $exception->getMessage());
    }

    public function test_authorization_and_server_failures_are_request_errors_with_preserved_status(): void
    {
        Http::fake([self::UPSERT_URL => Http::sequence()
            ->push(['status' => 'ok', 'result' => ['status' => 'completed']], 401)
            ->push(['status' => 'ok', 'result' => ['status' => 'completed']], 403)
            ->push(['status' => 'ok', 'result' => ['status' => 'completed']], 500)]);

        foreach ([401, 403, 500] as $status) {
            $exception = $this->expectVectorStoreError(
                fn () => $this->store($this->config(apiKey: 'prime-secret-key'))
                    ->upsert($this->space(), 'doc-1', 'v1', [$this->chunk()]),
                VectorStoreError::Request,
                $status,
            );

            $this->assertStringNotContainsString('prime-secret-key', $exception->getMessage());
        }
    }

    public function test_collection_not_found_is_not_generically_assumed_outside_the_inspect_contract(): void
    {
        Http::fake([self::UPSERT_URL => Http::response(['status' => 'ok'], 404)]);

        $this->expectVectorStoreError(
            fn () => $this->store()->upsert($this->space(), 'doc-1', 'v1', [$this->chunk()]),
            VectorStoreError::Request,
            404,
        );

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === self::COLLECTION_URL);
    }

    public function test_malformed_success_bodies_are_protocol_failures(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response('{"status":', 200)]);

        $this->expectVectorStoreError(
            fn () => $this->store()->ensureCollection($this->space()),
            VectorStoreError::Protocol,
        );

        Http::fake([self::UPSERT_URL => Http::response(['result' => ['status' => 'completed']], 200)]);

        $this->expectVectorStoreError(
            fn () => $this->store()->upsert($this->space(), 'doc-1', 'v1', [$this->chunk()]),
            VectorStoreError::Protocol,
        );
    }

    public function test_missing_collection_is_created_with_the_supplied_contract(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['status' => 'error', 'error' => 'Not found'], 404);
            }

            return Http::response(['status' => 'ok', 'result' => true], 200);
        });

        $this->store()->ensureCollection($this->space(dimension: 4, distance: DistanceMetric::Cosine));

        $requests = Http::recorded();

        $this->assertCount(2, $requests);
        [$inspect] = $requests[0];
        [$create] = $requests[1];

        $this->assertSame('GET', $inspect->method());
        $this->assertSame(self::COLLECTION_URL, $inspect->url());
        $this->assertSame('PUT', $create->method());
        $this->assertSame(self::COLLECTION_URL, $create->url());
        $this->assertSame(
            ['vectors' => ['size' => 4, 'distance' => 'Cosine']],
            $create->data(),
        );
    }

    public function test_compatible_existing_collection_is_accepted_without_create(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response($this->collectionBody(), 200)]);

        $this->store()->ensureCollection($this->space());

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_incompatible_existing_collection_is_rejected_explicitly(): void
    {
        $incompatible = [
            'dimensi berbeda' => $this->collectionBody(size: 3),
            'jarak berbeda' => $this->collectionBody(distance: 'Euclid'),
            'mode multi-vector' => ['status' => 'ok', 'result' => ['config' => ['params' => [
                'vectors' => ['default' => ['size' => 4, 'distance' => 'Cosine']],
            ]]]],
        ];

        Http::fake([self::COLLECTION_URL => Http::sequence()
            ->push($this->collectionBody(size: 3), 200)
            ->push($this->collectionBody(distance: 'Euclid'), 200)
            ->push(['status' => 'ok', 'result' => ['config' => ['params' => [
                'vectors' => ['default' => ['size' => 4, 'distance' => 'Cosine']],
            ]]]], 200)]);

        foreach (['dimensi berbeda', 'jarak berbeda', 'mode multi-vector'] as $case) {
            $this->expectVectorStoreError(
                fn () => $this->store()->ensureCollection($this->space()),
                VectorStoreError::Contract,
            );

            $this->assertNotSame('', $case);
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_authentication_failure_never_creates_a_collection(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response(['status' => 'error'], 401)]);

        $this->expectVectorStoreError(
            fn () => $this->store()->ensureCollection($this->space()),
            VectorStoreError::Request,
            401,
        );

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_server_failure_never_creates_a_collection(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response(['status' => 'ok', 'result' => ['config' => []]], 500)]);

        $this->expectVectorStoreError(
            fn () => $this->store()->ensureCollection($this->space()),
            VectorStoreError::Request,
            500,
        );

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_upsert_sends_wait_true_with_prime_controlled_metadata(): void
    {
        Http::fake([self::UPSERT_URL => Http::response($this->mutationBody(), 200)]);

        $uuid = '5c1f6a2e-9f4d-4a1e-bd5f-2f2b2a1c9e77';

        $this->store()->upsert(
            $this->space(),
            'doc-1',
            'v1',
            [$this->chunk(index: 0, pointId: 11), $this->chunk(index: 1, pointId: $uuid, page: null)],
            retrievalActive: true,
        );

        [$request] = $this->lastRequest();
        $body = $request->data();

        $this->assertSame('PUT', $request->method());
        $this->assertSame(self::UPSERT_URL, $request->url());
        $this->assertSame(11, $body['points'][0]['id']);
        $this->assertSame($uuid, $body['points'][1]['id']);
        $this->assertEquals(
            [
                'document_id' => 'doc-1',
                'document_version' => 'v1',
                'vector_space' => 'test:embedding',
                'page_number' => 1,
                'chunk_index' => 0,
                'chunk_locator' => 'p1#0',
                'content' => 'Isi chunk 0',
                'retrieval_active' => true,
            ],
            $body['points'][0]['payload'],
        );
        $this->assertEquals(self::VECTOR, $body['points'][0]['vector']);
        $this->assertNull($body['points'][1]['payload']['page_number']);
    }

    public function test_upsert_merges_bounded_provenance_metadata_into_payload(): void
    {
        Http::fake([self::UPSERT_URL => Http::response($this->mutationBody(), 200)]);
        $chunk = new VectorChunk(
            pointId: 11,
            vector: self::VECTOR,
            text: 'Isi chunk',
            locator: 'p1#0',
            chunkIndex: 0,
            pageNumber: 1,
            metadata: ['source_sha256' => str_repeat('a', 64), 'page_end' => 2, 'embedding_revision_id' => 1],
        );

        $this->store()->upsert($this->space(), 'doc-1', 'v1', [$chunk]);
        [$request] = $this->lastRequest();
        $payload = $request->data()['points'][0]['payload'];

        $this->assertSame(str_repeat('a', 64), $payload['source_sha256']);
        $this->assertSame(2, $payload['page_end']);
        $this->assertSame(1, $payload['embedding_revision_id']);
        $this->assertSame('doc-1', $payload['document_id']);
        $this->assertTrue($payload['retrieval_active']);
    }

    public function test_upsert_rejects_invalid_input_before_any_request(): void
    {
        $store = $this->store($this->config(apiKey: 'prime-secret-key'));

        // Vektor dengan panjang berbeda dari dimensi kontrak.
        try {
            $store->upsert($this->space(), 'doc-1', 'v1', [$this->chunk(vector: [0.1, 0.2, 0.3])]);
            $this->fail('Dimensi vektor tidak cocok harus ditolak.');
        } catch (InvalidArgumentException) {
        }

        foreach ([[NAN], [0.1, 0.2, 0.3, INF], ['bukan-angka']] as $vector) {
            try {
                $store->upsert($this->space(), 'doc-1', 'v1', [$this->chunk(vector: $vector)]);
                $this->fail('Vektor tidak berhingga harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }

        foreach (['', '   '] as $blank) {
            try {
                $store->upsert($this->space(), $blank, 'v1', [$this->chunk()]);
                $this->fail('Identitas dokumen kosong harus ditolak.');
            } catch (InvalidArgumentException) {
            }

            try {
                $store->upsert($this->space(), 'doc-1', $blank, [$this->chunk()]);
                $this->fail('Versi dokumen kosong harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }

        foreach (['bukan-uuid', '12'] as $invalidPointId) {
            try {
                $store->upsert($this->space(), 'doc-1', 'v1', [$this->chunk(pointId: $invalidPointId)]);
                $this->fail('ID titik harus unsigned int atau UUID.');
            } catch (InvalidArgumentException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_search_uses_query_points_with_bounded_top_k_and_prime_filters(): void
    {
        Http::fake([self::QUERY_URL => Http::response($this->queryBody(), 200)]);

        $results = $this->store()->search($this->space(), new RetrievalQuery(
            vector: self::VECTOR,
            limit: 2,
            documentId: 'doc-1',
            documentVersion: 'v1',
            onlyActive: true,
        ));

        [$request] = $this->lastRequest();

        $this->assertSame(self::QUERY_URL, $request->url());
        $this->assertSame('POST', $request->method());

        $body = $request->data();

        $this->assertEquals(self::VECTOR, $body['query']);
        $this->assertSame(2, $body['limit']);
        $this->assertSame(
            ['document_id', 'document_version', 'vector_space', 'page_number', 'chunk_index', 'chunk_locator', 'content', 'document_code', 'section_identifier', 'section_title', 'page_start', 'page_end', 'stable_chunk_identity'],
            $body['with_payload'],
        );
        $this->assertEquals([
            'must' => [
                ['key' => 'vector_space', 'match' => ['value' => 'test:embedding']],
                ['key' => 'document_id', 'match' => ['value' => 'doc-1']],
                ['key' => 'document_version', 'match' => ['value' => 'v1']],
                ['key' => 'retrieval_active', 'match' => ['value' => true]],
            ],
        ], $body['filter']);

        $this->assertCount(1, $results);

        $result = $results[0];

        $this->assertInstanceOf(VectorSearchResult::class, $result);
        $this->assertSame(11, $result->pointId);
        $this->assertSame(0.87, $result->score);
        $this->assertSame('doc-1', $result->documentId);
        $this->assertSame('v1', $result->documentVersion);
        $this->assertSame('test:embedding', $result->vectorSpace);
        $this->assertSame('p3#2', $result->locator);
        $this->assertSame(3, $result->pageNumber);
        $this->assertSame('DOC-1', $result->provenance['document_code']);
        $this->assertSame('1.2', $result->provenance['section_identifier']);
        $this->assertSame(2, $result->provenance['page_start']);
        $this->assertSame(3, $result->provenance['page_end']);
        $this->assertSame('stable-2', $result->provenance['stable_chunk_identity']);
    }

    public function test_search_preserves_chunks_without_recognized_section_provenance(): void
    {
        $body = $this->queryBody();
        unset($body['result']['points'][0]['payload']['section_identifier'], $body['result']['points'][0]['payload']['section_title']);
        Http::fake([self::QUERY_URL => Http::response($body, 200)]);

        $results = $this->store()->search($this->space(), new RetrievalQuery(vector: self::VECTOR, limit: 2));

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->provenance['section_identifier']);
        $this->assertNull($results[0]->provenance['section_title']);
        $this->assertSame('Isi chunk', $results[0]->text);
    }

    public function test_search_eligibility_filter_depends_only_on_explicit_query_state(): void
    {
        Http::fake([self::QUERY_URL => Http::response($this->queryBody(), 200)]);

        $this->store()->search($this->space(), new RetrievalQuery(
            vector: self::VECTOR,
            limit: 2,
            documentId: 'doc-1',
            onlyActive: false,
        ));

        [$request] = $this->lastRequest();
        $filter = $request->data()['filter'];

        // Tanpa permintaan eksplisit hanya filter identitas dokumen yang dikirim.
        $this->assertEquals([
            'must' => [
                ['key' => 'vector_space', 'match' => ['value' => 'test:embedding']],
                ['key' => 'document_id', 'match' => ['value' => 'doc-1']],
            ],
        ], $filter);
    }

    public function test_search_rejects_out_of_range_top_k_and_wrong_dimension_before_request(): void
    {
        $store = $this->store($this->config(maxRetrievalLimit: 4));

        foreach ([0, -1, 5] as $limit) {
            try {
                $store->search($this->space(), new RetrievalQuery(vector: self::VECTOR, limit: $limit));
                $this->fail('Top-K di luar rentang harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }

        foreach ([[0.1, 0.2], ['x'], []] as $vector) {
            try {
                $store->search($this->space(), new RetrievalQuery(vector: $vector, limit: 2));
                $this->fail('Vektor query tidak sesuai dimensi harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_malformed_search_results_are_protocol_failures(): void
    {
        Http::fake([self::QUERY_URL => Http::sequence()
            ->push(['status' => 'ok', 'result' => ['points' => 'bukan-daftar']], 200)
            ->push(['status' => 'ok', 'result' => ['points' => [['id' => 1, 'score' => 0.5]]]], 200)
            ->push(['status' => 'ok', 'result' => ['points' => [[
                'id' => 1,
                'score' => 0.5,
                'payload' => ['document_id' => '', 'document_version' => 'v1', 'vector_space' => 'x', 'chunk_locator' => 'p1#0', 'content' => 'isi'],
            ]]]], 200)
            ->push(['status' => 'ok', 'result' => ['points' => [[
                'id' => 1,
                'score' => 'tinggi',
                'payload' => ['document_id' => 'doc-1', 'document_version' => 'v1', 'vector_space' => 'x', 'chunk_locator' => 'p1#0', 'content' => 'isi'],
            ]]]], 200)]);

        foreach (['points bukan daftar', 'payload tidak ada', 'provenance kosong', 'skor tidak numerik'] as $case) {
            $exception = $this->expectVectorStoreError(
                fn () => $this->store()->search($this->space(), new RetrievalQuery(vector: self::VECTOR, limit: 2)),
                VectorStoreError::Protocol,
            );

            $this->assertNotSame('', $case);
            $this->assertStringNotContainsString('bukan-daftar', $exception->getMessage());
        }
    }

    public function test_upsert_rejects_batches_beyond_the_bound_and_empty_batches(): void
    {
        $store = $this->store($this->config(maxUpsertPoints: 2));

        foreach ([[], [$this->chunk(0), $this->chunk(1), $this->chunk(2)]] as $chunks) {
            try {
                $store->upsert($this->space(), 'doc-1', 'v1', $chunks);
                $this->fail('Batch di luar batas harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_delete_version_builds_the_filter_and_waits_for_completion(): void
    {
        Http::fake([self::DELETE_URL => Http::response($this->mutationBody(), 200)]);

        $this->store()->deleteVersion('doc-1', 'v9');

        [$request] = $this->lastRequest();

        $this->assertSame('POST', $request->method());
        $this->assertSame(self::DELETE_URL, $request->url());
        $this->assertEquals([
            'filter' => [
                'must' => [
                    ['key' => 'document_id', 'match' => ['value' => 'doc-1']],
                    ['key' => 'document_version', 'match' => ['value' => 'v9']],
                ],
            ],
        ], $request->data());
    }

    public function test_publication_activation_updates_only_scoped_points_and_requires_completion(): void
    {
        Http::fake([self::PAYLOAD_URL => Http::response($this->mutationBody(), 200)]);

        // Filter memuat ruang vektor: aktivasi tidak boleh menyentuh titik milik
        // embedding space lain walau identitas dokumen/versinya sama.
        $this->store()->setRetrievalActive($this->space(), 'doc-1', 'v9', true);

        [$request] = $this->lastRequest();

        $this->assertSame('POST', $request->method());
        $this->assertSame(self::PAYLOAD_URL, $request->url());
        $this->assertEquals([
            'payload' => ['retrieval_active' => true],
            'filter' => [
                'must' => [
                    ['key' => 'vector_space', 'match' => ['value' => $this->space()->identity]],
                    ['key' => 'document_id', 'match' => ['value' => 'doc-1']],
                    ['key' => 'document_version', 'match' => ['value' => 'v9']],
                ],
            ],
        ], $request->data());
    }

    public function test_publication_activation_rejects_empty_scope_and_incomplete_mutations(): void
    {
        $store = $this->store();
        foreach ([['', 'v1'], ['doc-1', '']] as [$documentId, $documentVersion]) {
            try {
                $store->setRetrievalActive($this->space(), $documentId, $documentVersion, true);
                $this->fail('Scope kosong harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }
        Http::assertNothingSent();

        Http::fake([self::PAYLOAD_URL => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200)]);
        $this->expectVectorStoreError(
            fn () => $this->store()->setRetrievalActive($this->space(), 'doc-1', 'v9', true),
            VectorStoreError::Protocol,
        );
    }

    public function test_delete_version_rejects_empty_scope_before_any_request(): void
    {
        $store = $this->store();

        foreach ([['', 'v1'], ['doc-1', ''], ['   ', 'v1']] as [$documentId, $documentVersion]) {
            try {
                $store->deleteVersion($documentId, $documentVersion);
                $this->fail('Scope kosong harus ditolak.');
            } catch (InvalidArgumentException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_delete_failure_is_never_reported_as_success(): void
    {
        Http::fake([self::DELETE_URL => Http::sequence()
            ->push(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200)
            ->push($this->mutationBody(), 500)]);

        $this->expectVectorStoreError(
            fn () => $this->store()->deleteVersion('doc-1', 'v1'),
            VectorStoreError::Protocol,
        );

        $this->expectVectorStoreError(
            fn () => $this->store()->deleteVersion('doc-1', 'v1'),
            VectorStoreError::Request,
            500,
        );
    }

    public function test_upsert_failure_is_never_reported_as_success(): void
    {
        Http::fake([self::UPSERT_URL => Http::sequence()
            ->push(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200)
            ->push(['status' => 'ok', 'result' => ['status' => 'wait_timeout']], 200)]);

        foreach (['acknowledged', 'wait_timeout'] as $status) {
            $this->expectVectorStoreError(
                fn () => $this->store()->upsert($this->space(), 'doc-1', 'v1', [$this->chunk()]),
                VectorStoreError::Protocol,
            );

            $this->assertNotSame('', $status);
        }
    }

    public function test_inspect_collection_reads_exact_count_and_vector_config(): void
    {
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody(size: 3072, distance: 'Cosine')),
            self::COLLECTION_URL.'/points/count' => Http::response(['status' => 'ok', 'result' => ['count' => 76]]),
        ]);

        $snapshot = $this->store()->inspectCollection();

        $this->assertTrue($snapshot->exists);
        $this->assertSame(76, $snapshot->exactPointCount);
        $this->assertSame(3072, $snapshot->vectorSize);
        $this->assertSame('Cosine', $snapshot->distance);

        [, $countRequest] = Http::recorded()->all();
        $this->assertSame('POST', $countRequest[0]->method());
        // Wajib exact dan tanpa filter ruang vektor.
        $this->assertSame(['exact' => true], $countRequest[0]->data());
    }

    public function test_inspect_collection_reports_absent_collection_without_counting(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response(['status' => 'error'], 404)]);

        $snapshot = $this->store()->inspectCollection();

        $this->assertFalse($snapshot->exists);
        $this->assertNull($snapshot->exactPointCount);
        $this->assertCount(1, Http::recorded());
    }

    public function test_inspect_collection_rejects_http_error(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response('', 500)]);

        $this->expectVectorStoreError(fn () => $this->store()->inspectCollection(), VectorStoreError::Request, 500);
    }

    public function test_inspect_collection_rejects_malformed_exact_count(): void
    {
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody()),
            self::COLLECTION_URL.'/points/count' => Http::response(['status' => 'ok', 'result' => ['count' => 'many']]),
        ]);

        $this->expectVectorStoreError(fn () => $this->store()->inspectCollection(), VectorStoreError::Protocol);
    }

    public function test_inspect_collection_rejects_unsupported_multi_vector_schema(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response([
            'status' => 'ok',
            'result' => ['config' => ['params' => ['vectors' => ['dense' => ['size' => 4, 'distance' => 'Cosine']]]]],
        ])]);

        $this->expectVectorStoreError(fn () => $this->store()->inspectCollection(), VectorStoreError::Contract);
    }

    public function test_inspect_collection_rejects_unknown_distance_metric(): void
    {
        Http::fake([self::COLLECTION_URL => Http::response($this->collectionBody(distance: 'Hamming'))]);

        $this->expectVectorStoreError(fn () => $this->store()->inspectCollection(), VectorStoreError::Contract);
    }

    public function test_inspect_collection_sends_no_mutating_request(): void
    {
        Http::fake([
            self::COLLECTION_URL => Http::response($this->collectionBody()),
            self::COLLECTION_URL.'/points/count' => Http::response(['status' => 'ok', 'result' => ['count' => 0]]),
        ]);

        $this->store()->inspectCollection();

        // Hanya baca metadata (GET) dan count (POST /points/count) yang boleh
        // terkirim; tidak ada upsert, delete, payload update, atau create.
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET'
            && ! ($request->method() === 'POST' && str_ends_with($request->url(), '/points/count')));
    }

    public function test_application_container_resolves_the_prime_boundary_to_qdrant(): void
    {
        $this->assertInstanceOf(QdrantVectorStore::class, $this->app->make(VectorStore::class));
    }

    /** @return array{0: Request, 1: mixed} */
    private function lastRequest(): array
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'Tidak ada permintaan Qdrant yang tercatat.');

        return $recorded->last();
    }

    /** @return array<string, mixed> */
    private function queryBody(): array
    {
        return [
            'status' => 'ok',
            'result' => ['points' => [[
                'id' => 11,
                'score' => 0.87,
                'payload' => [
                    'document_id' => 'doc-1',
                    'document_version' => 'v1',
                    'vector_space' => 'test:embedding',
                    'page_number' => 3,
                    'chunk_index' => 2,
                    'chunk_locator' => 'p3#2',
                    'content' => 'Isi chunk',
                    'document_code' => 'DOC-1',
                    'section_identifier' => '1.2',
                    'section_title' => 'Section',
                    'page_start' => 2,
                    'page_end' => 3,
                    'stable_chunk_identity' => 'stable-2',
                ],
            ]]],
        ];
    }

    private function config(
        ?string $apiKey = null,
        string $url = self::ENDPOINT,
        string $collection = self::COLLECTION,
        int $connectTimeout = 5,
        int $requestTimeout = 20,
        int $maxUpsertPoints = 3,
        int $maxRetrievalLimit = 4,
    ): QdrantConfig {
        return new QdrantConfig(
            url: $url,
            apiKey: $apiKey,
            collection: $collection,
            connectTimeout: $connectTimeout,
            requestTimeout: $requestTimeout,
            maxUpsertPoints: $maxUpsertPoints,
            maxRetrievalLimit: $maxRetrievalLimit,
        );
    }

    private function store(?QdrantConfig $config = null): QdrantVectorStore
    {
        $config ??= $this->config();
        $factory = $this->app->make(Factory::class);

        return new QdrantVectorStore(new QdrantTransport($factory, $config), $config);
    }

    private function space(int $dimension = 4, DistanceMetric $distance = DistanceMetric::Cosine): VectorSpace
    {
        return new VectorSpace(identity: 'test:embedding', dimension: $dimension, distance: $distance);
    }

    /** @param list<int|float> $vector */
    private function chunk(
        int $index = 0,
        int|string $pointId = 11,
        array $vector = self::VECTOR,
        ?int $page = 1,
    ): VectorChunk {
        return new VectorChunk(
            pointId: $pointId,
            vector: $vector,
            text: 'Isi chunk '.$index,
            locator: 'p1#'.$index,
            chunkIndex: $index,
            pageNumber: $page,
        );
    }

    /** @return array<string, mixed> */
    private function collectionBody(int $size = 4, string $distance = 'Cosine'): array
    {
        return [
            'status' => 'ok',
            'result' => ['config' => ['params' => ['vectors' => ['size' => $size, 'distance' => $distance]]]],
        ];
    }

    /** @return array<string, mixed> */
    private function mutationBody(string $status = 'completed'): array
    {
        return ['status' => 'ok', 'result' => ['status' => $status, 'operation_id' => 7]];
    }

    private function expectVectorStoreError(
        callable $operation,
        VectorStoreError $kind,
        ?int $status = null,
    ): VectorStoreException {
        try {
            $operation();
        } catch (VectorStoreException $exception) {
            $this->assertSame($kind, $exception->kind);

            if ($status !== null) {
                $this->assertSame($status, $exception->httpStatus);
            }

            return $exception;
        }

        $this->fail('VectorStoreException tidak dilempar untuk kategori '.$kind->value.'.');
    }
}
