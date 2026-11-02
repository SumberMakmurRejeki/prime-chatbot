<?php

namespace App\Services\AI;

/**
 * Perbandingan vektor probe yang konservatif dan deterministik.
 *
 * Toleransi per elemen dipakai, bukan cosine similarity: cosine similarity bisa
 * mendekati 1.0 untuk dua vektor yang seluruh elemennya bergeser seragam,
 * sehingga perbedaan model/parameter dapat lolos. Kesetaraan SHA-256 juga tidak
 * dipakai karena provider yang sama dapat mengubah pembulatan float saat
 * serialisasi ulang. Toleransi absolut 1e-6 dan relatif 1e-6 berada di atas
 * derau pembulatan float biasa (sekitar 1e-7) tetapi jauh di bawah pergeseran
 * yang ditimbulkan model atau parameter generasi yang berbeda.
 */
final class EmbeddingVectorComparator
{
    public const MAX_ABSOLUTE_DELTA = 1.0e-6;

    public const MAX_RELATIVE_DELTA = 1.0e-6;

    private function __construct() {}

    /** Vektor sah: list angka hingga, tepat sepanjang dimensi ruang. */
    public static function isValidVector(mixed $vector, int $dimension): bool
    {
        if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== $dimension) {
            return false;
        }

        foreach ($vector as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kode divergensi pertama yang ditemukan, atau null bila seluruh elemen
     * berada dalam toleransi.
     *
     * Validasi bentuk diulang di sini sebagai batas pertahanan terakhir:
     * perbandingan float biasa tidak dapat mengenali NaN (semua perbandingan
     * dengan NaN bernilai false, termasuk NaN <= toleransi), sehingga vektor
     * non-finite bisa lolos sebagai "setara". INF dan -INF ikut ditolak karena
     * selisih INF - INF menghasilkan NaN.
     *
     * @param  array<int, int|float>  $reference
     * @param  array<int, int|float>  $candidate
     */
    public static function divergenceCode(array $reference, array $candidate): ?string
    {
        if (! self::isValidVector($reference, count($reference)) || ! self::isValidVector($candidate, count($candidate))) {
            return 'vector_value_divergence';
        }

        if (count($reference) !== count($candidate)) {
            return 'vector_length_mismatch';
        }

        foreach ($reference as $index => $expected) {
            $expected = (float) $expected;
            $actual = (float) $candidate[$index];
            $delta = abs($expected - $actual);

            if ($delta <= self::MAX_ABSOLUTE_DELTA) {
                continue;
            }

            if ($delta / max(1.0, abs($expected)) > self::MAX_RELATIVE_DELTA) {
                return 'vector_value_divergence';
            }
        }

        return null;
    }
}
