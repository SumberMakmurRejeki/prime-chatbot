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
 *   provider yang dijadwalkan pada record terpercaya. Pada bukti sandbox PRIME
 *   yang terekam URI ini adalah path store, sehingga kecocokan membuktikan
 *   keanggotaan store, bukan file individual tertentu;
 * - `import_identity` provider sama persis dengan token generasi impor pada
 *   record terpercaya. Kode, versi, dan store tidak membedakan generasi impor:
 *   dokumen yang diimpor ulang tetap memakai kode dan versi yang sama di store
 *   yang sama, sehingga tanpa token ini sisa impor lama tidak dapat dibedakan
 *   dari revisi yang sah.
 *
 * Token identitas pada peta tepercaya wajib berasal dari registry indeksasi
 * PRIME (`knowledge_document_gemini_indexings.import_identity` melalui kandidat
 * {@see GeminiRetrievalEligibilityService}), bukan dari respons Gemini atau
 * masukan pengguna. Record tepercaya tanpa token yang dapat dipakai selalu
 * gagal tertutup, dan token yang dipakai dua record sekaligus ditolak karena
 * atribusi sitasinya ambigu.
 *
 * Perbandingan dilakukan dengan `!==` terhadap string; nomor versi tidak pernah
 * dikonversi ke angka, token identitas tidak pernah dinormalisasi (spasi atau
 * huruf besar/kecil membuatnya token lain), dan URI store tidak pernah diubah
 * menjadi resource per-dokumen. Kegagalan apa pun bersifat fail-closed.
 *
 * Dipakai oleh {@see GeminiGroundedAnswerService} untuk memverifikasi anotasi
 * sebelum jawaban dan sitasi disimpan/ditampilkan. Pemeta tidak mengakses
 * database atau memeriksa izin; kandidat tepercaya harus disediakan oleh
 * logika aplikasi sebelum pemanggilan ini.
 */
final class KnowledgeCitationMapper
{
    /**
     * @param  array<array-key, mixed>  $annotation  anotasi mentah dari respons Gemini
     * @param  array<string, array{version?: mixed, document_uri?: mixed, import_identity?: mixed}>  $trustedDocuments  document_code terbit => versi kanonik, provider-URI bukti, dan token generasi impor dari registry indeksasi
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
        $importIdentity = $metadata['import_identity'] ?? null;
        $documentUri = $annotation['document_uri'] ?? null;

        // Versi PRIME kanonik adalah string ("1", "1.0", "1.1", "2.0"); nilai
        // numerik provider ditolak, bukan dikonversi. Token generasi impor wajib
        // berbentuk kanonik ('g' + 48 hex) seperti yang ditulis pengindeks PRIME,
        // sehingga anotasi lama maupun token karangan tidak pernah lolos.
        if (! is_string($documentCode) || $documentCode === ''
            || ! is_string($version) || $version === ''
            || ! is_string($importIdentity) || ! self::isCanonicalImportIdentity($importIdentity)
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
            || ! is_string($trusted['import_identity'] ?? null)
            || ! self::isCanonicalImportIdentity($trusted['import_identity'])
            || $trusted['import_identity'] !== $importIdentity
            || $this->identityIsSharedWithAnotherTrustedRecord($trustedDocuments, $importIdentity)
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
     * @param  array<string, array{version?: mixed, document_uri?: mixed, import_identity?: mixed}>  $trustedDocuments
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
     * Token generasi impor disimpan pada baris indeksasi; kemunculan yang sama
     * pada dua record sekaligus menandakan registry janggal, sehingga kandidat
     * tersebut tidak boleh mengesahkan sitasi mana pun.
     *
     * @param  array<string, array{version?: mixed, document_uri?: mixed, import_identity?: mixed}>  $trustedDocuments
     */
    private function identityIsSharedWithAnotherTrustedRecord(array $trustedDocuments, string $importIdentity): bool
    {
        $matches = 0;

        foreach ($trustedDocuments as $trusted) {
            if (is_array($trusted) && ($trusted['import_identity'] ?? null) === $importIdentity) {
                $matches++;

                if ($matches > 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Bentuk token generasi impor yang ditulis PRIME saat mengindeks: 'g' + 48
     * hex. Token di luar bentuk ini tidak pernah berasal dari registry
     * indeksasi, jadi tidak boleh dipakai sebagai kunci pembanding.
     */
    private static function isCanonicalImportIdentity(string $importIdentity): bool
    {
        return preg_match('/^g[a-f0-9]{48}$/D', $importIdentity) === 1;
    }
}
