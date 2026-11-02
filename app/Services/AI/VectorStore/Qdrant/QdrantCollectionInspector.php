<?php

namespace App\Services\AI\VectorStore\Qdrant;

/**
 * Batas pembacaan (read-only) untuk penilaian kesiapan pengetahuan/indeks.
 *
 * Sengaja bukan bagian dari kontrak VectorStore: VectorStore adalah kontrak
 * umum vector store, sedangkan snapshot keadaan collection bersifat spesifik
 * Qdrant dan tidak boleh memaksa semua implementasi/fake retrieval menambahkan
 * metode count. Layanan kesiapan hanya boleh bergantung pada batas read-only
 * ini, bukan pada seluruh kemampuan mutasi QdrantVectorStore.
 */
interface QdrantCollectionInspector
{
    public function inspectCollection(): QdrantCollectionSnapshot;
}
