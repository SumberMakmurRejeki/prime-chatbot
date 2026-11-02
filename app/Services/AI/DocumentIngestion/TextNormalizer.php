<?php

namespace App\Services\AI\DocumentIngestion;

final class TextNormalizer
{
    /**
     * Versi algoritma normalisasi. Ikut menentukan pipeline fingerprint dan
     * identitas chunk, jadi perilaku normalisasi hanya boleh berubah bersama versi ini.
     */
    public const VERSION = 1;

    public function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw PdfIngestionException::invalidUtf8();
        }

        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C);

        if ($text === false) {
            throw PdfIngestionException::invalidUtf8();
        }

        $text = str_replace(["\t", "\u{00A0}"], ' ', $text);
        $lines = explode("\n", $text);
        $normalizedLines = [];
        $previousBlank = true;

        $inListBlock = false;

        foreach ($lines as $line) {
            $line = rtrim($line);

            $indent = '';
            $content = $line;

            if (preg_match('/^(\h*)(.*)$/u', $line, $match) === 1) {
                $indent = $match[1];
                $content = $match[2];
            }

            $isMarker = preg_match('/^(?:[-*+]|\d+[.)])\s+\S/u', $content) === 1;

            if ($content !== '' && $isMarker) {
                // Penanda list selalu menetapkan state, termasuk hasil normalisasi sebelumnya.
                $line = $content;
                $inListBlock = true;
            } elseif ($content !== '' && $indent !== '' && $inListBlock) {
                // Baris lanjutan item list memakai indentasi kanonik dua spasi.
                $line = '  '.$content;
            } elseif ($content === '') {
                $line = '';
                $inListBlock = false;
            } else {
                $line = $content;
            }

            $line = preg_replace('/(?<=\S)[\p{Zs} ]+/u', ' ', $line) ?? $line;

            if ($line === '') {
                if (! $previousBlank && $normalizedLines !== []) {
                    $normalizedLines[] = '';
                }
                $previousBlank = true;

                continue;
            }

            $normalizedLines[] = $line;
            $previousBlank = false;
        }

        while ($normalizedLines !== [] && end($normalizedLines) === '') {
            array_pop($normalizedLines);
        }

        return implode("\n", $normalizedLines);
    }
}
