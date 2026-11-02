<?php

namespace App\Services\AI\VectorStore\Qdrant;

/**
 * Potongan read-only keadaan satu collection Qdrant untuk penilaian kesiapan.
 *
 * Objek ini bukan bukti kompatibilitas ruang vektor: ia hanya melaporkan apa
 * yang terbaca pada saat pengamatan. exactPointCount null berarti count eksak
 * tidak dapat dipercaya dibaca — saat ini hanya ketika collection tidak
 * ditemukan (exists false).
 *
 * Ketiadaan collection TIDAK membuktikan apa pun tentang masa lalu atau masa
 * depan: ia hanya menyatakan collection terkonfigurasi tidak ditemukan saat
 * pengamatan. Collection bisa pernah ada lalu dihapus di luar jalur kode
 * aplikasi, collection lain bisa berisi data, ingestion bisa dimulai setelah
 * pengamatan ini, dan karena itu snapshot absence BUKAN izin bootstrap.
 */
final readonly class QdrantCollectionSnapshot
{
    private function __construct(
        public bool $exists,
        public ?int $exactPointCount,
        public ?int $vectorSize,
        public ?string $distance,
    ) {}

    public static function absent(): self
    {
        return new self(false, null, null, null);
    }

    public static function present(int $exactPointCount, int $vectorSize, string $distance): self
    {
        return new self(true, $exactPointCount, $vectorSize, $distance);
    }
}
