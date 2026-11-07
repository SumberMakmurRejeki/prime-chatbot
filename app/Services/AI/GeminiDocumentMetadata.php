<?php

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Metadata bisnis PRIME pada dokumen File Search.
 *
 * Kedua nilai selalu string: `"2.0"`, `"1.10"`, dan `"1.0"` adalah versi
 * bisnis yang berbeda, bukan angka. Tipe PHP inilah yang mencegah SDK
 * menebak tipe lewat is_numeric() pada batas integrasi Gemini.
 */
final readonly class GeminiDocumentMetadata
{
    public const MAX_DOCUMENT_CODE_LENGTH = 64;

    public const MAX_VERSION_LENGTH = 32;

    public function __construct(
        public string $documentCode,
        public string $version,
    ) {
        if (! self::isCanonicalValue($documentCode, self::MAX_DOCUMENT_CODE_LENGTH)) {
            throw new InvalidArgumentException('Document code must be a non-empty canonical string.');
        }

        if (! self::isCanonicalValue($version, self::MAX_VERSION_LENGTH)) {
            throw new InvalidArgumentException('Document version must be a non-empty canonical string.');
        }
    }

    /**
     * Payload customMetadata Gemini: selalu stringValue, tidak pernah numericValue.
     *
     * @return array{document_code: string, version: string}
     */
    public function toImportMetadata(): array
    {
        return [
            'document_code' => $this->documentCode,
            'version' => $this->version,
        ];
    }

    private static function isCanonicalValue(string $value, int $maxLength): bool
    {
        return $value !== ''
            && trim($value) === $value
            && mb_strlen($value) <= $maxLength
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
    }
}
