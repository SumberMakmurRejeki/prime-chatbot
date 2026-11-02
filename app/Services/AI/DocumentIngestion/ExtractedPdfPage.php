<?php

namespace App\Services\AI\DocumentIngestion;

final readonly class ExtractedPdfPage
{
    public function __construct(
        public int $pageNumber,
        public string $text,
    ) {
        if ($pageNumber < 1) {
            throw PdfIngestionException::unsupportedContent();
        }
    }
}
