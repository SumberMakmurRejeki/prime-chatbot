<?php

namespace App\Services\AI\DocumentIngestion;

use InvalidArgumentException;

/**
 * Metadata bisnis dokumen yang WAJIB diberikan pemanggil tepercaya.
 *
 * Pipeline sengaja tidak menebak identitas dokumen dari isi prosa PDF
 * (keputusan AI-3B0 bagian N) supaya provenance selalu jujur.
 */
final readonly class DocumentMetadata
{
    public function __construct(
        public string $documentCode,
        public string $documentVersion,
        public ?string $machineCode = null,
    ) {
        if (trim($this->documentCode) === '' || trim($this->documentVersion) === '') {
            throw new InvalidArgumentException('Kode dan versi dokumen wajib diisi.');
        }
    }
}
