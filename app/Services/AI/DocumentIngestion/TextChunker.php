<?php

namespace App\Services\AI\DocumentIngestion;

final readonly class TextChunker
{
    public const SECTION_DETECTOR_VERSION = 1;

    public const ALGORITHM_VERSION = 1;

    /**
     * @param  array<int, string>  $pages
     * @return list<ChunkDraft>
     */
    public function chunk(array $pages, PdfIngestionConfig $config): array
    {
        $drafts = [];
        $sectionPath = null;
        $sectionTitle = null;
        $headingPage = null;
        $chunk = null;

        foreach ($pages as $pageNumber => $pageText) {
            $pageNumber = (int) $pageNumber;

            if ($pageText === '') {
                continue;
            }

            foreach ($this->units($pageText) as $unit) {
                $heading = $unit['heading'];

                if ($heading !== null) {
                    $chunk = $this->flush($drafts, $chunk);
                    $sectionPath = $heading['path'];
                    $sectionTitle = $heading['title'];
                    $headingPage = $pageNumber;
                }

                foreach ($this->pieces($pageText, $unit['start'], $unit['end'], $config) as $piece) {
                    if (trim($piece['text']) === '') {
                        continue;
                    }

                    $gap = $chunk === null ? '' : $this->gap($chunk, $pageText, $pageNumber, $piece['start']);

                    if ($chunk !== null && ($chunk['path'] !== $sectionPath
                        || $this->wouldExceed($chunk['text'], $gap, $piece['text'], $config))) {
                        $chunk = $this->flush($drafts, $chunk);
                        $gap = '';
                    }

                    if ($chunk === null) {
                        $chunk = ['text' => '', 'spans' => [], 'path' => $sectionPath, 'title' => $sectionTitle, 'heading_page' => $headingPage];
                    }

                    $chunk['text'] .= $gap.$piece['text'];
                    $chunk['spans'][] = ['page' => $pageNumber, 'start' => $piece['start'], 'end' => $piece['end'], 'gap' => $gap];
                }
            }
        }

        if ($chunk !== null) {
            $drafts[] = $this->toDraft($chunk);
        }

        return $drafts;
    }

    /**
     * @param  list<ChunkDraft>  $drafts
     * @param  array<string, mixed>|null  $chunk
     * @return null
     */
    private function flush(array &$drafts, ?array $chunk): ?array
    {
        if ($chunk !== null && trim($chunk['text']) !== '') {
            $drafts[] = $this->toDraft($chunk);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    private function toDraft(array $chunk): ChunkDraft
    {
        $spans = [];

        foreach ($chunk['spans'] as $index => $span) {
            $separator = isset($chunk['spans'][$index + 1]) ? $chunk['spans'][$index + 1]['gap'] : '';
            $spans[] = new SourceSpan($span['page'], $span['start'], $span['end'], $separator);
        }

        return new ChunkDraft(
            text: $chunk['text'],
            sourceSpans: $spans,
            pageStart: $spans[0]->page,
            pageEnd: $spans[count($spans) - 1]->page,
            sectionPath: $chunk['path'],
            sectionTitle: $chunk['title'],
            headingOriginPage: $chunk['heading_page'],
        );
    }

    /** @param array<string, mixed>|null $chunk */
    private function gap(?array $chunk, string $pageText, int $pageNumber, int $start): string
    {
        if ($chunk === null || $chunk['spans'] === []) {
            return '';
        }

        $previous = $chunk['spans'][count($chunk['spans']) - 1];

        if ($previous['page'] !== $pageNumber) {
            return "\n";
        }

        $gap = substr($pageText, $previous['end'], $start - $previous['end']);

        return preg_match('/^\s*$/', $gap) === 1 ? $gap : '';
    }

    /**
     * @return list<array{text:string, start:int, end:int, heading:array{path:list<int>, title:string}|null}>
     */
    private function units(string $pageText): array
    {
        $units = [];
        $current = null;
        $previousEnd = null;

        foreach (preg_split('/\n/', $pageText, -1, PREG_SPLIT_OFFSET_CAPTURE) as [$line, $offset]) {
            $length = strlen($line);
            $lineEnd = $offset + $length;

            if ($line === '') {
                $current = $this->closeUnit($units, $current, $pageText);
                $previousEnd = null;

                continue;
            }

            $heading = $this->detectHeading($line);

            if ($heading !== null) {
                $current = $this->closeUnit($units, $current, $pageText);
                $units[] = ['text' => $line, 'start' => $offset, 'end' => $lineEnd, 'heading' => $heading];
                $previousEnd = null;

                continue;
            }

            // Penanda list memisahkan unit agar item list menjadi batas alami.
            if ($this->startsListItem($line)) {
                $current = $this->closeUnit($units, $current, $pageText);
            }

            if ($current === null) {
                $current = ['start' => $offset, 'end' => $lineEnd];
            } else {
                $current['end'] = $lineEnd;
            }

            $previousEnd = $lineEnd;
        }

        $this->closeUnit($units, $current, $pageText);

        return $units;
    }

    /**
     * @param  list<array{text:string, start:int, end:int, heading:array{path:list<int>, title:string}|null}>  $units
     * @param  array{start:int, end:int}|null  $current
     * @return null
     */
    private function closeUnit(array &$units, ?array $current, string $pageText): ?array
    {
        if ($current !== null) {
            $units[] = [
                'text' => substr($pageText, $current['start'], $current['end'] - $current['start']),
                'start' => $current['start'],
                'end' => $current['end'],
                'heading' => null,
            ];
        }

        return null;
    }

    /** @return list<array{text:string, start:int, end:int}> */
    private function pieces(string $pageText, int $start, int $end, PdfIngestionConfig $config): array
    {
        if ($this->fits($pageText, $start, $end, $config)) {
            return [['text' => substr($pageText, $start, $end - $start), 'start' => $start, 'end' => $end]];
        }

        $pieces = [];
        $this->splitRegion($pageText, $start, $end, "\n", $config, $pieces);

        return $pieces;
    }

    /**
     * @param  list<array{text:string, start:int, end:int}>  $pieces
     */
    private function splitRegion(string $pageText, int $start, int $end, string $separator, PdfIngestionConfig $config, array &$pieces): void
    {
        $region = substr($pageText, $start, $end - $start);
        $cursor = null;
        $lastEnd = null;

        foreach (preg_split('/'.preg_quote($separator, '/').'/', $region, -1, PREG_SPLIT_OFFSET_CAPTURE) as [$part, $offset]) {
            $partStart = $start + $offset;
            $partEnd = $partStart + strlen($part);

            if ($part === '') {
                continue;
            }

            if (! $this->fits($pageText, $partStart, $partEnd, $config)) {
                if ($cursor !== null) {
                    $pieces[] = ['text' => substr($pageText, $cursor, $lastEnd - $cursor), 'start' => $cursor, 'end' => $lastEnd];
                    $cursor = null;
                }

                if ($separator === ' ') {
                    $this->splitGraphemes($pageText, $partStart, $partEnd, $config, $pieces);
                } else {
                    $this->splitRegion($pageText, $partStart, $partEnd, ' ', $config, $pieces);
                }

                continue;
            }

            if ($cursor === null) {
                $cursor = $partStart;
                $lastEnd = $partEnd;

                continue;
            }

            // Gabungkan hanya selama gabungan tetap di bawah batas keras;
            // pemisah tetap bagian dari substring sehingga span tetap eksak.
            if (! $this->fits($pageText, $cursor, $partEnd, $config)) {
                $pieces[] = ['text' => substr($pageText, $cursor, $lastEnd - $cursor), 'start' => $cursor, 'end' => $lastEnd];
                $cursor = $partStart;
            }

            $lastEnd = $partEnd;
        }

        if ($cursor !== null) {
            $pieces[] = ['text' => substr($pageText, $cursor, $lastEnd - $cursor), 'start' => $cursor, 'end' => $lastEnd];
        }
    }

    /**
     * @param  list<array{text:string, start:int, end:int}>  $pieces
     */
    private function splitGraphemes(string $pageText, int $start, int $end, PdfIngestionConfig $config, array &$pieces): void
    {
        $region = substr($pageText, $start, $end - $start);
        $matches = [];
        if (preg_match_all('/\X/u', $region, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw PdfIngestionException::invalidUtf8();
        }

        $cursor = null;
        $lastEnd = null;

        foreach ($matches[0] as [$grapheme, $offset]) {
            $graphemeStart = $start + $offset;
            $graphemeEnd = $graphemeStart + strlen($grapheme);

            if ($cursor === null) {
                $cursor = $graphemeStart;
                $lastEnd = $graphemeEnd;

                continue;
            }

            if (! $this->fits($pageText, $cursor, $graphemeEnd, $config)) {
                $pieces[] = ['text' => substr($pageText, $cursor, $lastEnd - $cursor), 'start' => $cursor, 'end' => $lastEnd];
                $cursor = $graphemeStart;
            }

            $lastEnd = $graphemeEnd;
        }

        if ($cursor === null) {
            throw PdfIngestionException::chunkLimitExceeded();
        }

        $pieces[] = ['text' => substr($pageText, $cursor, $lastEnd - $cursor), 'start' => $cursor, 'end' => $lastEnd];
    }

    private function fits(string $pageText, int $start, int $end, PdfIngestionConfig $config): bool
    {
        $slice = substr($pageText, $start, $end - $start);
        $graphemes = preg_match_all('/\X/u', $slice);

        return $graphemes !== false
            && $graphemes <= $config->hardChunkGraphemes
            && strlen($slice) <= $config->hardChunkBytes;
    }

    private function wouldExceed(string $current, string $gap, string $incoming, PdfIngestionConfig $config): bool
    {
        $combined = $current.$gap.$incoming;
        $graphemes = preg_match_all('/\X/u', $combined);

        return $graphemes === false
            || $graphemes > $config->targetChunkGraphemes
            || strlen($combined) > $config->hardChunkBytes;
    }

    private function startsListItem(string $line): bool
    {
        return preg_match('/^(?:[-*+•]|\d{1,3}[.)])\s+\S/u', $line) === 1;
    }

    /** @return array{path:list<int>, title:string}|null */
    private function detectHeading(string $line): ?array
    {
        if (preg_match('/^(\d{1,2}(?:\.\d{1,2}){0,2})\.?\s+(\S.*)$/u', $line, $match) !== 1) {
            return null;
        }

        $components = explode('.', $match[1]);
        foreach ($components as $component) {
            // Menolak nol atau angka berpadding nol seperti "1.000" / "07.2".
            if ($component === '0' || (strlen($component) > 1 && $component[0] === '0')) {
                return null;
            }
        }

        $title = trim($match[2]);

        if (mb_strlen($title) > 100
            || preg_match('/^\p{Lu}/u', $title) !== 1
            || preg_match('/[.,;:!?]$/u', $title) === 1) {
            return null;
        }
        if (count($components) === 1 && mb_strtoupper($title, 'UTF-8') !== $title) {
            return null;
        }
        $words = preg_split('/\s+/u', $title) ?: [];
        if (count($words) > 1 && preg_match('/^\p{Lu}/u', $words[1]) !== 1) {
            // Judul bersuku kata dengan kata kedua huruf kecil lebih mungkin prosa
            // atau item prosedur bernomor daripada heading dokumen.
            return null;
        }

        return ['path' => array_map('intval', $components), 'title' => $title];
    }
}
