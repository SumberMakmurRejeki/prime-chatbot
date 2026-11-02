<?php

namespace App\Services\AI\VectorStore\Qdrant;

use App\Services\AI\VectorStore\DistanceMetric;
use App\Services\AI\VectorStore\RetrievalQuery;
use App\Services\AI\VectorStore\VectorChunk;
use App\Services\AI\VectorStore\VectorSearchResult;
use App\Services\AI\VectorStore\VectorSpace;
use App\Services\AI\VectorStore\VectorStore;
use App\Services\AI\VectorStore\VectorStoreError;
use App\Services\AI\VectorStore\VectorStoreException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Satu-satunya tempat REST Qdrant dipetakan ke kontrak VectorStore.
 *
 * Transport memakai Laravel Http langsung: JSON, timeout eksplisit, redirect
 * tidak diikuti, tanpa retry, dan opsional header api-key. Semua kegagalan
 * jaringan/HTTP/protokol diterjemahkan menjadi VectorStoreException yang aman
 * (tanpa endpoint, API key, atau isi respons mentah Qdrant). Validasi masukan
 * pemanggil tetap memakai InvalidArgumentException karena bukan kegagalan
 * lingkungan.
 */
final class QdrantVectorStore implements QdrantCollectionInspector, VectorStore
{
    /**
     * Payload yang benar-benar dibutuhkan PRIME saat retrieval: identitas
     * dokumen/versi, ruang vektor, dan provenance sitasi.
     *
     * @var list<string>
     */
    private const PAYLOAD_FIELDS = [
        'document_id',
        'document_version',
        'vector_space',
        'page_number',
        'chunk_index',
        'chunk_locator',
        'content',
        'document_code',
        'section_identifier',
        'section_title',
        'page_start',
        'page_end',
        'stable_chunk_identity',
    ];

    public function __construct(
        private readonly QdrantTransport $transport,
        private readonly QdrantConfig $config,
    ) {}

    /**
     * Hanya status HTTP 200 dari endpoint readiness yang dianggap siap.
     *
     * 503 dengan badan yang tampak valid (misalnya berisi "passed"), redirect,
     * dan kegagalan koneksi tidak pernah dianggap siap. Kesiapan service tidak
     * membuktikan kompatibilitas collection; itu urusan ensureCollection().
     */
    public function isReady(): bool
    {
        try {
            return $this->request('GET', $this->path('/readyz'))->status() === 200;
        } catch (VectorStoreException) {
            return false;
        }
    }

    /**
     * Idempoten: collection yang sudah cocok diterima tanpa membuat ulang.
     *
     * Hanya respons 404 yang memicu pembuatan collection. Kegagalan autentikasi,
     * server, atau jaringan lain tidak pernah diperlakukan sebagai "collection
     * belum ada".
     */
    public function ensureCollection(VectorSpace $space): void
    {
        $collectionPath = $this->path('/collections/'.$this->config->collection);
        $inspect = $this->request('GET', $collectionPath);

        if ($inspect->status() === 404) {
            $created = $this->successPayload($this->request('PUT', $collectionPath, [
                'vectors' => [
                    'size' => $space->dimension,
                    'distance' => $space->distance->value,
                ],
            ]));

            if (($created['result'] ?? null) !== true) {
                throw new VectorStoreException('Collection Qdrant tidak dapat dibuat.', VectorStoreError::Protocol);
            }

            return;
        }

        $payload = $this->successPayload($inspect);
        $this->assertCompatibleVectorConfig($payload['result']['config']['params']['vectors'] ?? null, $space);
    }

    public function upsert(
        VectorSpace $space,
        string $documentId,
        string $documentVersion,
        array $chunks,
        bool $retrievalActive = true,
    ): void {
        if ($chunks === [] || count($chunks) > $this->config->maxUpsertPoints) {
            throw new InvalidArgumentException('Jumlah chunk di luar batas batch yang diizinkan.');
        }

        $this->assertDocumentScope($documentId, $documentVersion);

        $points = [];

        foreach ($chunks as $chunk) {
            if (! $chunk instanceof VectorChunk) {
                throw new InvalidArgumentException('Setiap chunk harus berupa VectorChunk.');
            }

            $points[] = [
                'id' => $this->assertPointId($chunk->pointId),
                'vector' => $this->assertVector($chunk->vector, $space),
                'payload' => array_merge($chunk->metadata, [
                    'document_id' => $documentId,
                    'document_version' => $documentVersion,
                    'vector_space' => $space->identity,
                    'page_number' => $chunk->pageNumber,
                    'chunk_index' => $chunk->chunkIndex,
                    'chunk_locator' => $chunk->locator,
                    'content' => $chunk->text,
                    'retrieval_active' => $retrievalActive,
                ]),
            ];
        }

        $payload = $this->successPayload($this->request(
            'PUT',
            $this->path('/collections/'.$this->config->collection.'/points?wait=true'),
            ['points' => $points],
        ));

        $this->assertMutationCompleted($payload, 'Upsert Qdrant');
    }

    /** @return list<VectorSearchResult> */
    public function search(VectorSpace $space, RetrievalQuery $query): array
    {
        $vector = $this->assertVector($query->vector, $space);

        if ($query->limit < 1 || $query->limit > $this->config->maxRetrievalLimit) {
            throw new InvalidArgumentException('Batas retrieval di luar rentang yang diizinkan.');
        }

        $body = [
            'query' => $vector,
            'limit' => $query->limit,
            // Hanya payload yang dibutuhkan PRIME; payload lain tidak diminta.
            'with_payload' => self::PAYLOAD_FIELDS,
        ];

        $body['filter'] = $this->filterFor($query, $space);

        $payload = $this->successPayload($this->request(
            'POST',
            $this->path('/collections/'.$this->config->collection.'/points/query'),
            $body,
        ));

        $points = $payload['result']['points'] ?? null;

        if (! is_array($points)) {
            throw new VectorStoreException('Hasil pencarian Qdrant tidak sesuai kontrak.', VectorStoreError::Protocol);
        }

        return array_map($this->searchResultFrom(...), array_values($points));
    }

    public function deleteVersion(string $documentId, string $documentVersion): void
    {
        $this->assertDocumentScope($documentId, $documentVersion);

        // Filter dibangun adapter dari identitas PRIME; pemanggil tidak perlu
        // mengetahui ID setiap titik untuk mengganti satu versi dokumen.
        $payload = $this->successPayload($this->request(
            'POST',
            $this->path('/collections/'.$this->config->collection.'/points/delete?wait=true'),
            [
                'filter' => [
                    'must' => [
                        ['key' => 'document_id', 'match' => ['value' => $documentId]],
                        ['key' => 'document_version', 'match' => ['value' => $documentVersion]],
                    ],
                ],
            ],
        ));

        $this->assertMutationCompleted($payload, 'Hapus versi dokumen');
    }

    /**
     * Mengaktifkan/menonaktifkan titik satu versi dokumen tanpa embedding ulang.
     *
     * Filter memuat ruang vektor, identitas, dan versi: titik dari embedding
     * space lain tidak boleh ikut aktif, sehingga gerbang publikasi tetap
     * fail-closed walau ruang vektor aktif berubah.
     */
    public function setRetrievalActive(
        VectorSpace $space,
        string $documentId,
        string $documentVersion,
        bool $active,
    ): void {
        $this->assertDocumentScope($documentId, $documentVersion);

        $payload = $this->successPayload($this->request(
            'POST',
            $this->path('/collections/'.$this->config->collection.'/points/payload?wait=true'),
            [
                'payload' => ['retrieval_active' => $active],
                'filter' => [
                    'must' => [
                        ['key' => 'vector_space', 'match' => ['value' => $space->identity]],
                        ['key' => 'document_id', 'match' => ['value' => $documentId]],
                        ['key' => 'document_version', 'match' => ['value' => $documentVersion]],
                    ],
                ],
            ],
        ));

        $this->assertMutationCompleted($payload, 'Aktivasi retrieval dokumen');
    }

    /**
     * Pembacaan read-only keadaan collection untuk penilaian kesiapan.
     *
     * Hanya GET metadata collection dan, bila collection ada, POST count dengan
     * exact=true: tidak ada pembuatan, upsert, atau penghapusan apa pun. Hanya
     * respons 404 yang diartikan "collection belum ada"; status non-2xx lain,
     * respons rusak, dan konfigurasi vektor yang tidak didukung dilempar sebagai
     * VectorStoreException supaya pemanggil gagal tertutup, bukan menganggap 0.
     *
     * Filter ruang vektor sengaja tidak dipakai: jumlah tersaring nol tidak
     * pernah membuktikan collection kosong secara global.
     */
    public function inspectCollection(): QdrantCollectionSnapshot
    {
        $collectionPath = $this->path('/collections/'.$this->config->collection);
        $inspect = $this->request('GET', $collectionPath);

        if ($inspect->status() === 404) {
            // Menurut kontrak lifecycle, collection dibuat tepat sebelum upsert
            // pertama, jadi aplikasi ini belum pernah menulis titik ke
            // collection yang SAAT INI tidak ditemukan. Ini bukan bukti
            // collection belum pernah ada atau tidak akan ada lagi: penghapusan
            // di luar jalur kode tidak dapat disingkirkan, sehingga jumlah titik
            // dilaporkan null, bukan nol.
            return QdrantCollectionSnapshot::absent();
        }

        $payload = $this->successPayload($inspect);
        $vectorConfig = $this->readSingleVectorConfig($payload['result']['config']['params']['vectors'] ?? null);

        $count = $this->successPayload($this->request(
            'POST',
            $this->path('/collections/'.$this->config->collection.'/points/count'),
            ['exact' => true],
        ));
        $exact = $count['result']['count'] ?? null;

        // Angka dari statistik collection (mis. points_count) bersifat perkiraan
        // dan tidak boleh dipakai sebagai bukti kosong/terisi.
        if (! is_int($exact) || $exact < 0) {
            throw new VectorStoreException('Jumlah titik Qdrant tidak terbaca secara eksak.', VectorStoreError::Protocol);
        }

        return QdrantCollectionSnapshot::present($exact, $vectorConfig['size'], $vectorConfig['distance']);
    }

    /**
     * @return array{must: list<array{key: string, match: array{value: bool|string}}>}
     */
    private function filterFor(RetrievalQuery $query, VectorSpace $space): array
    {
        $must = [];

        // Pemisahan ruang vektor bersifat wajib: model embedding yang berbeda
        // tidak boleh tercampur dalam satu hasil retrieval walau dimensinya sama.
        $must[] = ['key' => 'vector_space', 'match' => ['value' => $space->identity]];

        if ($query->documentId !== null) {
            $must[] = ['key' => 'document_id', 'match' => ['value' => $query->documentId]];
        }

        if ($query->documentVersion !== null) {
            $must[] = ['key' => 'document_version', 'match' => ['value' => $query->documentVersion]];
        }

        if ($query->onlyActive) {
            $must[] = ['key' => 'retrieval_active', 'match' => ['value' => true]];
        }

        return ['must' => $must];
    }

    /**
     * Memetakan satu ScoredPoint ke bentuk hasil milik PRIME; ketiadaan
     * provenance wajib dianggap respons rusak, bukan hasil kosong.
     */
    private function searchResultFrom(mixed $point): VectorSearchResult
    {
        if (! is_array($point)) {
            throw new VectorStoreException('Hasil pencarian Qdrant tidak sesuai kontrak.', VectorStoreError::Protocol);
        }

        $id = $point['id'] ?? null;
        $score = $point['score'] ?? null;
        $payload = $point['payload'] ?? null;

        if ((! is_int($id) && ! is_string($id)) || (! is_int($score) && ! is_float($score)) || ! is_array($payload)) {
            throw new VectorStoreException('Hasil pencarian Qdrant tidak sesuai kontrak.', VectorStoreError::Protocol);
        }

        $documentId = $payload['document_id'] ?? null;
        $documentVersion = $payload['document_version'] ?? null;
        $vectorSpace = $payload['vector_space'] ?? null;
        $locator = $payload['chunk_locator'] ?? null;
        $content = $payload['content'] ?? null;
        $pageNumber = $payload['page_number'] ?? null;
        $chunkIndex = $payload['chunk_index'] ?? null;
        $documentCode = $payload['document_code'] ?? null;
        $sectionIdentifier = $payload['section_identifier'] ?? null;
        $sectionTitle = $payload['section_title'] ?? null;
        $pageStart = $payload['page_start'] ?? null;
        $pageEnd = $payload['page_end'] ?? null;
        $stableChunkIdentity = $payload['stable_chunk_identity'] ?? null;

        if (! is_string($documentId) || $documentId === ''
            || ! is_string($documentVersion) || $documentVersion === ''
            || ! is_string($vectorSpace) || $vectorSpace === ''
            || ! is_string($locator) || $locator === ''
            || ! is_string($content)
            || ($pageNumber !== null && ! is_int($pageNumber))
            || ! is_int($chunkIndex)
            || ! is_string($documentCode) || $documentCode === ''
            || ($sectionIdentifier !== null && (! is_string($sectionIdentifier) || $sectionIdentifier === ''))
            || ($sectionTitle !== null && (! is_string($sectionTitle) || $sectionTitle === ''))
            || ! is_int($pageStart) || ! is_int($pageEnd)
            || ! is_string($stableChunkIdentity) || $stableChunkIdentity === ''
        ) {
            throw new VectorStoreException('Provenance hasil pencarian tidak lengkap.', VectorStoreError::Protocol);
        }

        return new VectorSearchResult(
            pointId: $id,
            score: (float) $score,
            documentId: $documentId,
            documentVersion: $documentVersion,
            vectorSpace: $vectorSpace,
            locator: $locator,
            pageNumber: $pageNumber,
            text: $content,
            provenance: [
                'document_code' => $documentCode,
                'document_version' => $documentVersion,
                'section_identifier' => $sectionIdentifier,
                'section_title' => $sectionTitle,
                'page_start' => $pageStart,
                'page_end' => $pageEnd,
                'chunk_index' => $chunkIndex,
                'stable_chunk_identity' => $stableChunkIdentity,
            ],
        );

    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertMutationCompleted(array $payload, string $operation): void
    {
        $status = $payload['result']['status'] ?? null;

        // `wait=true` memakai semantik sinkron: hanya `completed` yang berhasil;
        // `acknowledged`/`wait_timeout` tetap dianggap belum selesai.
        if (! is_string($status) || strtolower($status) !== 'completed') {
            throw new VectorStoreException($operation.' belum selesai diproses Qdrant.', VectorStoreError::Protocol);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertCompatibleVectorConfig(mixed $vectors, VectorSpace $space): void
    {
        $config = $this->readSingleVectorConfig($vectors);

        if ($config['size'] !== $space->dimension) {
            throw new VectorStoreException(
                "Dimensi collection Qdrant ({$config['size']}) tidak cocok dengan ruang vektor ({$space->dimension}).",
                VectorStoreError::Contract,
            );
        }

        $distance = DistanceMetric::tryFrom($config['distance']);

        if ($distance !== $space->distance) {
            throw new VectorStoreException(
                'Jarak collection Qdrant tidak cocok dengan kontrak ruang vektor yang disetujui.',
                VectorStoreError::Contract,
            );
        }
    }

    /**
     * Membaca satu-satunya konfigurasi vektor unnamed pada metadata collection.
     *
     * Dipakai bersama ensureCollection() dan inspectCollection() supaya bentuk
     * respons Qdrant hanya diurai di satu tempat. Konfigurasi multi-vector
     * (peta nama → params) belum didukung V1 dan dibedakan dari respons rusak
     * agar pemanggil gagal tertutup dengan alasan yang tepat.
     *
     * @return array{size: int, distance: string}
     */
    private function readSingleVectorConfig(mixed $vectors): array
    {
        if (! is_array($vectors)) {
            throw new VectorStoreException('Metadata collection Qdrant tidak terbaca.', VectorStoreError::Protocol);
        }

        if (! isset($vectors['size'], $vectors['distance'])) {
            // Mode multi-vector (peta nama → params) belum didukung V1.
            throw new VectorStoreException('Collection Qdrant tidak memakai satu vector unnamed.', VectorStoreError::Contract);
        }

        if (! is_int($vectors['size']) || ! is_string($vectors['distance'])) {
            throw new VectorStoreException('Metadata dimensi/jarak collection Qdrant tidak terbaca.', VectorStoreError::Protocol);
        }

        // Aturan skema tunggal: dimensi positif dengan metrik yang dikenal
        // DistanceMetric. Null dari tryFrom berarti metrik yang tidak dikenal,
        // jadi collection ini bukan indeks yang dibuat kontrak aplikasi.
        if ($vectors['size'] < 1 || DistanceMetric::tryFrom($vectors['distance']) === null) {
            throw new VectorStoreException('Konfigurasi vektor collection Qdrant tidak didukung.', VectorStoreError::Contract);
        }

        return ['size' => $vectors['size'], 'distance' => $vectors['distance']];
    }

    private function assertDocumentScope(string $documentId, string $documentVersion): void
    {
        if (trim($documentId) === '' || trim($documentVersion) === '') {
            throw new InvalidArgumentException('Identitas dokumen dan versi wajib diisi.');
        }
    }

    private function assertPointId(int|string $pointId): int|string
    {
        if (is_int($pointId) && $pointId >= 0) {
            return $pointId;
        }

        // Qdrant menerima unsigned integer atau UUID sebagai ID titik.
        if (is_string($pointId) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $pointId)) {
            return $pointId;
        }

        throw new InvalidArgumentException('ID titik harus unsigned integer atau UUID.');
    }

    /**
     * @param  array<int|string, mixed>  $vector
     * @return list<float|int>
     */
    private function assertVector(array $vector, VectorSpace $space): array
    {
        if (count($vector) !== $space->dimension) {
            throw new InvalidArgumentException('Panjang vector tidak sama dengan dimensi ruang vektor.');
        }

        foreach ($vector as $component) {
            if ((! is_int($component) && ! is_float($component)) || ! is_finite($component)) {
                throw new InvalidArgumentException('Komponen vector harus numerik dan berhingga.');
            }
        }

        return array_values($vector);
    }

    /**
     * Mengirim satu permintaan JSON ke Qdrant lewat transport terbatas.
     *
     * Satu operasi = satu percobaan: slice ini tidak menambahkan retry otomatis.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function request(string $method, string $path, ?array $body = null): Response
    {
        try {
            return $this->transport->client()->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            throw new VectorStoreException(
                'Qdrant tidak dapat dihubungi atau permintaan melewati batas waktu.',
                VectorStoreError::Connection,
                previous: $exception,
            );
        }
    }

    /**
     * Status non-2xx tidak pernah dianggap sukses meskipun badan tampak valid.
     *
     * @return array<string, mixed>
     */
    private function successPayload(Response $response): array
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            throw new VectorStoreException('Qdrant mengembalikan redirect yang tidak diizinkan.', VectorStoreError::Request, $status);
        }

        if ($status < 200 || $status >= 300) {
            throw new VectorStoreException('Permintaan ke Qdrant ditolak.', VectorStoreError::Request, $status);
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new VectorStoreException('Respons Qdrant tidak sesuai kontrak JSON.', VectorStoreError::Protocol);
        }

        return $payload;
    }

    private function path(string $suffix): string
    {
        return '/'.ltrim($suffix, '/');
    }
}
