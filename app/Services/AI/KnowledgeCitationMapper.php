<?php

namespace App\Services\AI;

/**
 * Pemeta minimal anotasi `file_citation` Gemini File Search ke kandidat sitasi
 * dokumen PRIME yang terbit.
 *
 * SDK laravel/ai v1.2.0 hanya memetakan `url_citation` ke koleksi sitasi
 * standar; anotasi `file_citation` hanya terbaca dari payload mentah respons
 * (mis. `step->raw->json('steps.0.content.0.annotations.0')`).
 *
 * Pemeta ini tidak menghubungkan diri ke runtime chatbot dan tidak pernah
 * memperlakukan `document_code`, `version`, `file_name`, atau `document_uri`
 * dari Gemini sebagai bukti identitas atau izin. Kecocokan yang diberikan
 * terbatas pada:
 * - metadata provider (`document_code` + `version` string) sama persis dengan
 *   record terbit pada pemetaan tepercaya yang diberikan pemanggil;
 * - `document_uri` yang dilaporkan provider sama persis dengan URI dari bukti
 *   provider yang dijadwalkan pada record tepercaya. Pada bukti sandbox PRIME
 *   yang terekam URI ini adalah path store, sehingga kecocokan membuktikan
 *   keanggotaan store, bukan file individual tertentu.
 *
 * Perbandingan dilakukan dengan `!==` terhadap string; nomor versi tidak pernah
 * dikonversi ke angka, dan URI store tidak pernah diubah menjadi resource
 * per-dokumen. Kegagalan apa pun bersifat fail-closed (ditolak).
 *
 * Pemeta ini tidak mengakses database dan tidak memverifikasi status izin;
 * lingkup dokumen yang berhak harus datang dari logika aplikasi tepercaya.
 */
final class KnowledgeCitationMapper
{
    /**
     * @param  array<array-key, mixed>  $annotation  anotasi mentah dari respons Gemini
     * @param  array<string, array{version: string, document_uri: string}>  $trustedDocuments  document_code terbit => versi kanonik string dan provider-URI bukti
     */
    public function map(array $annotation, array $trustedDocuments): ?KnowledgeCitation
    {
        if (($annotation['type'] ?? null) !== 'file_citation') {
            return null;
        }

        $metadata = $annotation['custom_metadata'] ?? null;

        if (! is_array($metadata)) {
            return null;
        }

        $documentCode = $metadata['document_code'] ?? null;
        $version = $metadata['version'] ?? null;
        $documentUri = $annotation['document_uri'] ?? null;

        // Versi PRIME kanonik adalah string ("1", "1.0", "1.1", "2.0"); nilai
        // numerik provider ditolak, bukan dikonversi.
        if (! is_string($documentCode) || $documentCode === ''
            || ! is_string($version) || $version === ''
            || ! is_string($documentUri) || $documentUri === ''
        ) {
            return null;
        }

        $trusted = $trustedDocuments[$documentCode] ?? null;

        if (! is_array($trusted)
            || ! is_string($trusted['version'] ?? null)
            || ! is_string($trusted['document_uri'] ?? null)
            || $trusted['document_uri'] === ''
            || $trusted['version'] !== $version
            || $trusted['document_uri'] !== $documentUri
        ) {
            return null;
        }

        $pageNumber = $annotation['page_number'] ?? null;

        return new KnowledgeCitation(
            matchedRecordCode: $documentCode,
            matchedRecordVersion: $trusted['version'],
            documentUri: $documentUri,
            pageNumber: is_int($pageNumber) && $pageNumber > 0 ? $pageNumber : null,
            providerFileName: $this->stringOrNull($annotation, 'file_name'),
            providerSource: $this->stringOrNull($annotation, 'source'),
        );
    }

    /**
     * Petakan banyak anotasi sekaligus; anotasi tidak dapat diverifikasi
     * dilewati, sitasi duplikat (record, URI, dan halaman sama) digabung ke
     * satu karena file individual tidak dapat dibedakan dari anotasi.
     *
     * @param  array<array-key, mixed>  $annotations
     * @param  array<string, array{version: string, document_uri: string}>  $trustedDocuments
     * @return list<KnowledgeCitation>
     */
    public function mapAll(array $annotations, array $trustedDocuments): array
    {
        $citations = [];
        $seen = [];

        foreach ($annotations as $annotation) {
            if (! is_array($annotation)) {
                continue;
            }

            $citation = $this->map($annotation, $trustedDocuments);

            if ($citation === null) {
                continue;
            }

            $key = $citation->matchedRecordCode."\0".$citation->matchedRecordVersion."\0".$citation->documentUri."\0".($citation->pageNumber ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $citations[] = $citation;
        }

        return $citations;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
