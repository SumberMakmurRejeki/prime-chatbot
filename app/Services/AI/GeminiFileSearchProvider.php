<?php

namespace App\Services\AI;

use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\Tools\FileSearch;
use RuntimeException;

/**
 * Provider Gemini PRIME yang menolak File Search tanpa batasan sumber.
 *
 * laravel/ai v1.2.0 merender nilai filter tanpa tanda kutip setiap kali
 * is_numeric() bernilai true, sehingga versi string seperti "3.0" keluar sebagai
 * version=3.0 dan tidak pernah cocok dengan metadata stringValue. Alih-alih
 * memakai heuristik itu, provider ini hanya menerima tool ber-scope
 * {@see GeminiRetrievalSourceTool} dan memakai ekspresi yang memang dibangun
 * dari literal string; tool lain ditolak sebelum ada permintaan.
 *
 * Karena penyaringan adalah persyaratan keamanan, tak ada jalur pengiriman
 * File Search yang lolos tanpa scope di provider koneksi ini; retry tanpa
 * metadata filter karena alasan apa pun juga tidak tersedia di batas ini.
 */
final class GeminiFileSearchProvider extends GeminiProvider
{
    public function fileSearchToolOptions(FileSearch $search): array
    {
        if (! $search instanceof GeminiRetrievalSourceTool) {
            throw new RuntimeException('Gemini File Search requires an eligible source scope.');
        }

        return [
            'file_search_store_names' => $search->ids(),
            'metadata_filter' => $search->metadataFilter(),
        ];
    }
}
