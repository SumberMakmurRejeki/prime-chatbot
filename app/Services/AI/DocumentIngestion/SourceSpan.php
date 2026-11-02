<?php

namespace App\Services\AI\DocumentIngestion;

/**
 * Span sumber menunjuk byte UTF-8 pada teks halaman yang telah dinormalisasi.
 * separatorAfter menyimpan tepat newline yang menghubungkan span berurutan.
 */
final readonly class SourceSpan
{
    public function __construct(
        public int $page,
        public int $start,
        public int $end,
        public string $separatorAfter = '',
    ) {
        if ($this->page < 1 || $this->start < 0 || $this->end < $this->start
            || preg_match('/^\n*$/', $this->separatorAfter) !== 1) {
            throw PdfIngestionException::unsupportedContent();
        }
    }
}
