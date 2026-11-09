<?php

namespace App\Services\AI;

use InvalidArgumentException;
use Laravel\Ai\Providers\Tools\FileSearch;

/**
 * Tool File Search yang hanya mencakup kandidat SOP eligible.
 *
 * Tool ini adalah satu-satunya bentuk File Search yang diterima
 * {@see GeminiFileSearchProvider}; tool tanpa scope dianggap retrieval tak
 * terbatas dan ditolak sebelum ada permintaan. Metadata filter dibangun dari
 * pasangan document_code/import_identity yang sudah diverifikasi, keduanya
 * berupa literal string kanonik. Dengan demikian impor stale atau duplikat
 * tanpa token identity yang diotorisasi tidak masuk scope.
 */
final class GeminiRetrievalSourceTool extends FileSearch
{
    /**
     * `version` tetap divalidasi kanonik saat pembentukan tool, namun bukan
     * kondisi filter provider: token `import_identity` sudah unik per generasi
     * impor dan versi tetap ditegakkan oleh kelayakan Laravel serta kontrak
     * sitasi.
     *
     * @var list<array{document_code: string, version: string, import_identity: string}>
     */
    private readonly array $allowedDocuments;

    /** @var list<GeminiRetrievalCandidate> */
    private readonly array $verifiedSources;

    private readonly string $sourceStore;

    /**
     * @param  list<GeminiRetrievalCandidate>  $eligibleSources
     */
    public function __construct(array $eligibleSources)
    {
        if ($eligibleSources === []) {
            throw new InvalidArgumentException('Gemini File Search retrieval requires at least one eligible source.');
        }

        $this->verifiedSources = array_values($eligibleSources);

        $stores = array_unique(array_map(
            static fn (GeminiRetrievalCandidate $source): string => $source->storeName,
            $eligibleSources,
        ));

        if (count($stores) !== 1) {
            throw new InvalidArgumentException('Gemini File Search retrieval requires exactly one store identity.');
        }

        $this->sourceStore = (string) reset($stores);
        $this->allowedDocuments = array_map(
            static function (GeminiRetrievalCandidate $source): array {
                return (new GeminiDocumentMetadata($source->documentCode, $source->version, $source->importIdentity))->toImportMetadata();
            },
            $eligibleSources,
        );

        parent::__construct([$this->sourceStore]);
    }

    /**
     * Kandidat sumber yang menjadi dasar tool ini; satu-satunya sumber
     * tepercaya untuk peta sitasi agar filter permintaan dan verifikasi
     * anotasi selalu berasal dari evaluasi kelayakan yang sama.
     *
     * @return list<GeminiRetrievalCandidate>
     */
    public function verifiedSources(): array
    {
        return $this->verifiedSources;
    }

    /**
     * Field $stores milik SDK bersifat publik dan bisa saja diubah pemanggil;
     * daftar store selalu dibaca dari identitas yang diverifikasi di sini.
     */
    public function ids(): array
    {
        return [$this->sourceStore];
    }

    /**
     * Ekspresi metadata filter AIP-160: setiap kandidat dikurung agar `OR`
     * tidak pernah menggabungkan document_code dengan import_identity milik
     * kandidat lain (presedensi `OR` lebih tinggi dari `AND` pada sintaks ini).
     *
     * Hanya dua kondisi yang dikirim. Versi tidak dipakai sebagai pembeda di
     * sisi provider karena satu versi dapat dimiliki beberapa dokumen,
     * sedangkan token import_identity hanya dimiliki satu kandidat.
     */
    public function metadataFilter(): string
    {
        return implode(' OR ', array_map(
            static fn (array $document): string => sprintf(
                '(document_code="%s" AND import_identity="%s")',
                $document['document_code'],
                $document['import_identity'],
            ),
            $this->allowedDocuments,
        ));
    }
}
