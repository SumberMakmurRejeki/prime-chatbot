<?php

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Metadata bisnis PRIME pada dokumen File Search.
 *
 * Kedua nilai selalu string: `"2.0"`, `"1.10"`, dan `"1.0"` adalah versi
 * bisnis yang berbeda, bukan angka. Token `importIdentity` membedakan setiap
 * generasi impor meskipun dokumen dan versinya sama.
 */
final readonly class GeminiDocumentMetadata
{
    public const MAX_DOCUMENT_CODE_LENGTH = 64;

    public const MAX_VERSION_LENGTH = 32;

    public const MAX_IMPORT_IDENTITY_LENGTH = 64;

    public function __construct(
        public string $documentCode,
        public string $version,
        public string $importIdentity,
    ) {
        if (! self::isCanonicalValue($documentCode, self::MAX_DOCUMENT_CODE_LENGTH)) {
            throw new InvalidArgumentException('Document code must be a non-empty canonical string.');
        }

        if (! self::isCanonicalValue($version, self::MAX_VERSION_LENGTH)) {
            throw new InvalidArgumentException('Document version must be a non-empty canonical string.');
        }

        if (! self::isCanonicalValue($importIdentity, self::MAX_IMPORT_IDENTITY_LENGTH)) {
            throw new InvalidArgumentException('Import identity must be a non-empty canonical string.');
        }
    }

    /**
     * Payload customMetadata Gemini: selalu stringValue, tidak pernah numericValue.
     *
     * @return array{document_code: string, version: string, import_identity: string}
     */
    public function toImportMetadata(): array
    {
        return [
            'document_code' => $this->documentCode,
            'version' => $this->version,
            'import_identity' => $this->importIdentity,
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
