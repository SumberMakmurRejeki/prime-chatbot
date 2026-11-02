<?php

namespace App\Services\AI;

use JsonException;

/**
 * Penyandian dan pensidik-jarian bukti referensi embedding.
 *
 * Keluaran probe disimpan sebagai teks JSON kanonik, bukan kolom JSON, supaya
 * checksum memverifikasi byte yang benar-benar tersimpan: kolom JSON MySQL
 * menormalisasi ulang dokumen (spasi, urutan kunci, presisi angka) sehingga
 * verifikasi integritas tidak lagi pasti.
 *
 * Envelope checksum mencakup seluruh metadata provenance, jadi manipulasi
 * langsung di database (mis. mengubah dimensi atau model) juga terdeteksi.
 * Kolom generated_at sengaja tidak ikut disandikan karena presisi timestamp
 * berbeda antara MySQL dan SQLite; nilainya informatif, bukan bukti integritas.
 */
final class EmbeddingEvidenceCodec
{
    /** Kolom yang dicakup checksum keutuhan bukti. */
    private const ENVELOPE_KEYS = [
        'reference_fingerprint',
        'probe_profile',
        'probe_profile_version',
        'provenance_fingerprint',
        'driver',
        'endpoint',
        'model_id',
        'dimension',
        'distance_metric',
        'generation_parameters',
        'source_configuration_id',
        'source_revision_id',
        'payload_checksum',
    ];

    private function __construct() {}

    /** JSON kanonik: kunci peta diurutkan rekursif, presisi float dipertahankan. */
    public static function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Embedding evidence payload cannot be encoded.', 0, $exception);
        }
    }

    /** @param array<string, list<int|float>> $outputs */
    public static function encodeOutputs(array $outputs): string
    {
        return self::canonicalJson($outputs);
    }

    /**
     * @return array<string, array<int, int|float>>|null null bila payload bukan peta vektor
     *
     * @phpstan-ignore-next-line return.type
     */
    public static function decodeOutputs(string $payload): ?array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        foreach ($decoded as $key => $vector) {
            if (! is_string($key) || ! is_array($vector)) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * Melengkapi atribut bukti dengan reference fingerprint dan checksum.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function seal(array $attributes): array
    {
        $attributes['payload_checksum'] = hash('sha256', (string) ($attributes['probe_outputs'] ?? ''));
        $attributes['reference_fingerprint'] = self::referenceFingerprint($attributes);
        $attributes['evidence_checksum'] = self::envelopeChecksum($attributes);

        return $attributes;
    }

    /**
     * Verifikasi ulang bukti tersimpan; false berarti bukti tidak dapat dipercaya.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function isSealed(array $attributes): bool
    {
        $payloadChecksum = (string) ($attributes['payload_checksum'] ?? '');
        $evidenceChecksum = (string) ($attributes['evidence_checksum'] ?? '');
        $referenceFingerprint = (string) ($attributes['reference_fingerprint'] ?? '');

        if ($payloadChecksum === '' || $evidenceChecksum === '' || $referenceFingerprint === '') {
            return false;
        }

        if (! hash_equals($payloadChecksum, hash('sha256', (string) ($attributes['probe_outputs'] ?? '')))) {
            return false;
        }

        if (! hash_equals($referenceFingerprint, self::referenceFingerprint($attributes))) {
            return false;
        }

        return hash_equals($evidenceChecksum, self::envelopeChecksum($attributes));
    }

    /** @param array<string, mixed> $attributes */
    private static function referenceFingerprint(array $attributes): string
    {
        return hash('sha256', self::canonicalJson([
            'provenance_fingerprint' => (string) ($attributes['provenance_fingerprint'] ?? ''),
            'probe_profile' => (string) ($attributes['probe_profile'] ?? ''),
            'probe_profile_version' => (int) ($attributes['probe_profile_version'] ?? 0),
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private static function envelopeChecksum(array $attributes): string
    {
        $envelope = [];

        foreach (self::ENVELOPE_KEYS as $key) {
            $envelope[$key] = $attributes[$key] ?? null;
        }

        return hash('sha256', self::canonicalJson($envelope));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }

        return $value;
    }
}
