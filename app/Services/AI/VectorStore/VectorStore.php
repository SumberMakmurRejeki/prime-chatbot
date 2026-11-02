<?php

namespace App\Services\AI\VectorStore;

/**
 * Batas vector store milik PRIME.
 *
 * Ingestion, retriever, agent, dan UI hanya berbicara lewat kontrak ini. Bentuk
 * endpoint REST, struktur respons, filter JSON, serta detail transport HTTP
 * Qdrant tetap berada di adapter, sehingga penggantian backend vector store
 * tidak menyentuh pemanggil aplikasi.
 */
interface VectorStore
{
    public function isReady(): bool;

    public function ensureCollection(VectorSpace $space): void;

    /** @param list<VectorChunk> $chunks */
    public function upsert(
        VectorSpace $space,
        string $documentId,
        string $documentVersion,
        array $chunks,
        bool $retrievalActive = true,
    ): void;

    /**
     * Gerbang publikasi: titik satu versi dokumen hanya boleh aktif bila
     * dokumen sudah READY di registry MySQL dan dipublikasikan eksplisit.
     *
     * Ruang vektor wajib ikut disaring supaya titik dari embedding space yang
     * tidak kompatibel tidak pernah ikut aktif dalam satu indeks.
     */
    public function setRetrievalActive(
        VectorSpace $space,
        string $documentId,
        string $documentVersion,
        bool $active,
    ): void;

    /** @return list<VectorSearchResult> */
    public function search(VectorSpace $space, RetrievalQuery $query): array;

    public function deleteVersion(string $documentId, string $documentVersion): void;
}
