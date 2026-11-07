<?php

namespace App\Services\AI;

/**
 * Hasil pencocokan anotasi `file_citation` Gemini File Search dengan record
 * dokumen PRIME tepercaya, beserta metadata yang hanya dilaporkan provider.
 *
 * Pembuktian yang dibawa jenis ini:
 * - `matchedRecordCode` dan `matchedRecordVersion` berasal dari record
 *   aplikasi tepercaya yang dicocokkan pemanggil, bukan dari respons Gemini;
 * - `documentUri` adalah URI yang dilaporkan provider dan nilainya sama dengan
 *   URI pada record tepercaya. Pada bukti sandbox PRIME yang terekam, URI ini
 *   adalah path File Search Store;
 * - `pageNumber`, `providerFileName`, dan `providerSource` dilaporkan provider
 *   dan tidak boleh dipakai sebagai bukti identitas atau izin.
 *
 * Jenis ini tidak membuktikan file individual mana yang dikutip: record PRIME
 * dicocokkan dari metadata provider, dan dokumentasi resmi hanya menyebut
 * `document_uri` sebagai "URI of the file" tanpa jaminan cakupan store atau
 * per-file. Karena itu sitasi tidak boleh disajikan sebagai bukti PDF tertentu.
 *
 * Jenis ini hanya boleh dibentuk oleh KnowledgeCitationMapper; keberadaan
 * anotasi `file_citation` pada respons Gemini tidak pernah cukup untuk
 * membuktikan dokumen sudah terbit atau sah.
 */
final class KnowledgeCitation
{
    public function __construct(
        public readonly string $matchedRecordCode,
        public readonly string $matchedRecordVersion,
        public readonly string $documentUri,
        public readonly ?int $pageNumber = null,
        public readonly ?string $providerFileName = null,
        public readonly ?string $providerSource = null,
    ) {}
}
