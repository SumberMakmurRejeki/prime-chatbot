<?php

namespace App\Services\AI\DocumentIngestion;

final readonly class ExtractedPdfText
{
    /** @param list<ExtractedPdfPage> $pages */
    public function __construct(public array $pages, public string $sourceSha256) {}
}
