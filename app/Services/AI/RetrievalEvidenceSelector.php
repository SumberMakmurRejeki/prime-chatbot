<?php

namespace App\Services\AI;

use App\Services\AI\VectorStore\VectorSearchResult;

/**
 * Pemilih bukti retrieval yang sadar-seksi. Kandidat vektor yang lebih lebar
 * dipersempit ke konteks akhir yang dibatasi, dengan memakai provenance
 * dokumen/versi/seksi yang sudah tersimpan PRIME. Skor similarity asli tidak
 * diubah dan provenance tidak dikarang; hanya urutan/anggota hasil yang dipilih.
 */
final class RetrievalEvidenceSelector
{
    /** Batas maksimum bukti yang boleh dipakai sebagai konteks akhir chatbot. */
    public const MAX_FINAL_RESULTS = 5;

    /**
     * @param  list<VectorSearchResult>  $candidates  kandidat vektor terurut menurun berdasarkan skor
     * @return list<VectorSearchResult>
     */
    public function select(array $candidates, int $limit): array
    {
        $limit = max(0, min($limit, self::MAX_FINAL_RESULTS));

        if ($limit === 0) {
            return [];
        }

        /** @var list<VectorSearchResult> $selected */
        $selected = [];
        /** @var array<string, true> $selectedIdentities */
        $selectedIdentities = [];

        $families = $this->anchoredFamilies($candidates, $limit);
        $familyPosition = array_fill(0, count($families), 0);

        while (count($selected) < $limit) {
            $added = false;

            foreach ($families as $familyIndex => $indexes) {
                while (isset($indexes[$familyPosition[$familyIndex]])) {
                    $candidateIndex = $indexes[$familyPosition[$familyIndex]++];
                    $before = count($selected);
                    $this->push($selected, $selectedIdentities, $candidates[$candidateIndex]);
                    if (count($selected) > $before) {
                        $added = true;
                        break;
                    }
                }

                if (count($selected) >= $limit) {
                    break;
                }
            }

            if (! $added) {
                break;
            }
        }

        foreach ($candidates as $candidate) {
            if (count($selected) >= $limit) {
                break;
            }

            $this->push($selected, $selectedIdentities, $candidate);
        }

        // Skor seri tetap deterministik karena pengurutan PHP stabil terhadap urutan input.
        usort($selected, static fn (VectorSearchResult $left, VectorSearchResult $right): int => $right->score <=> $left->score);

        return $selected;
    }

    /**
     * Kelompokkan indeks kandidat menjadi keluarga seksi koheren yang salah satu
     * anggotanya sudah masuk Top-`limit` mentah. Urutan keluarga mengikuti kemunculan
     * pertama sehingga keluaran deterministik.
     *
     * @param  list<VectorSearchResult>  $candidates
     * @return list<list<int>>
     */
    private function anchoredFamilies(array $candidates, int $limit): array
    {
        /** @var array<string, array{sections: array<string, true>, indexes: list<int>}> $groups */
        $groups = [];

        foreach ($candidates as $index => $candidate) {
            $section = $candidate->provenance['section_identifier'] ?? null;
            $parent = $this->parentSection($section);

            if ($parent === null) {
                continue;
            }

            $key = $candidate->documentId."\0".$candidate->documentVersion."\0".$parent;
            $groups[$key]['sections'][(string) $section] = true;
            $groups[$key]['indexes'][] = $index;
        }

        $families = [];

        foreach ($groups as $group) {
            if (count($group['sections']) < 2) {
                continue;
            }

            if (min($group['indexes']) >= $limit) {
                continue;
            }

            $families[] = $group['indexes'];
        }

        return $families;
    }

    /**
     * Ambil seksi induk dari identifier hierarkis bertitik, mis. "11.4" -> "11".
     * Identifier null atau non-hierarkis dianggap tidak punya struktur seksi.
     */
    private function parentSection(mixed $section): ?string
    {
        if (! is_string($section) || preg_match('/^\d+(?:\.\d+)+$/', $section) !== 1) {
            return null;
        }

        return substr($section, 0, (int) strrpos($section, '.'));
    }

    /**
     * Tambahkan kandidat sambil menghindari bukti duplikat (mis. chunk yang sama
     * muncul dengan point id berbeda).
     *
     * @param  list<VectorSearchResult>  $selected
     * @param  array<string, true>  $selectedIdentities
     */
    private function push(array &$selected, array &$selectedIdentities, VectorSearchResult $candidate): void
    {
        $identity = $this->identity($candidate);

        if (isset($selectedIdentities[$identity])) {
            return;
        }

        $selectedIdentities[$identity] = true;
        $selected[] = $candidate;
    }

    private function identity(VectorSearchResult $candidate): string
    {
        $stable = $candidate->provenance['stable_chunk_identity'] ?? null;
        $chunk = is_string($stable) && $stable !== '' ? $stable : (string) $candidate->pointId;

        return $candidate->documentId."\0".$candidate->documentVersion."\0".$chunk;
    }
}
