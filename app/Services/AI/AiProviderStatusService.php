<?php

namespace App\Services\AI;

use App\Models\AiProviderConfigurationRevision;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentGeminiIndex;

/**
 * Kontrak status provider AI untuk halaman pengaturan.
 *
 * Tiga sumber status disusun terpisah agar tidak saling menyiratkan:
 * konfigurasi lokal (chat dan store), bukti indeksasi yang tersimpan, dan
 * verifikasi live yang hanya dijalankan bila diminta eksplisit. snapshot()
 * tidak pernah menghubungi provider; verifyConfiguredStore() melakukan tepat
 * satu GET read-only ke store terkonfigurasi dan tidak menyimpan hasilnya
 * sebagai bukti permanen.
 */
final class AiProviderStatusService
{
    public function __construct(
        private readonly ChatProviderResolver $chatProviders,
        private readonly GeminiFileSearchOperations $fileSearch,
    ) {}

    /**
     * Status baca biasa dari konfigurasi lokal dan bukti tersimpan.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $chat = $this->chatConfiguration();
        $store = $this->fileSearch->configuredStoreIdentity();
        $fileSearchState = $this->fileSearchState($store);
        $documents = $this->knowledgeDocumentSummary();
        $indexing = $this->indexingSummary($store, $documents['total']);

        return [
            'chat' => $chat,
            'file_search' => [
                'state' => $fileSearchState->value,
                'message' => $fileSearchState->message(),
                'store' => $store,
                'indexing_enabled' => $this->indexingEnabled(),
            ],
            'gemini_indexing' => $indexing,
            'knowledge_documents' => $documents,
            'limitations' => $this->limitations($chat, $store, $indexing, $documents),
        ];
    }

    /**
     * Verifikasi akses store terkonfigurasi: satu GET read-only tanpa unggah,
     * impor, penghapusan, atau perubahan pointer revisi.
     *
     * @return array<string, mixed>
     */
    public function verifyConfiguredStore(): array
    {
        $readiness = $this->fileSearch->checkStoreReadiness();

        return [
            // State di sini milik pemeriksaan live, bukan status konfigurasi
            // statis, sehingga hasilnya sengaja tidak pernah di-cache.
            'state' => $readiness->state->value,
            'message' => $readiness->state->safeMessage(),
            'store' => $readiness->storeName ?? $this->fileSearch->configuredStoreIdentity(),
            'reason_code' => $readiness->reasonCode,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{state: string, message: string, driver: ?string, model_id: ?string} */
    private function chatConfiguration(): array
    {
        $active = $this->chatProviders->activeChatRevisions();
        $eligible = $active
            ->filter(fn (AiProviderConfigurationRevision $revision): bool => $this->chatProviders->isEligible($revision))
            ->values();

        if ($eligible->count() === 1) {
            return $this->chatPayload(ChatConfigurationState::Configured, $eligible->first());
        }

        if ($eligible->count() > 1) {
            return $this->chatPayload(ChatConfigurationState::Unknown, null);
        }

        // Revisi aktif dengan driver tak didukung dapat dibedakan dari
        // konfigurasi yang sekadar belum lengkap atau belum lulus uji.
        $unsupported = $active->first(
            fn (AiProviderConfigurationRevision $revision): bool => ! ChatProviderResolver::supportsDriver($revision->driver),
        );

        return $unsupported === null
            ? $this->chatPayload(ChatConfigurationState::NotConfigured, null)
            : $this->chatPayload(ChatConfigurationState::Unsupported, $unsupported);
    }

    /**
     * @return array{state: string, message: string, driver: ?string, model_id: ?string}
     */
    private function chatPayload(ChatConfigurationState $state, ?AiProviderConfigurationRevision $revision): array
    {
        return [
            'state' => $state->value,
            'message' => $state->message(),
            'driver' => $revision?->driver,
            'model_id' => $revision?->model_id,
        ];
    }

    private function fileSearchState(?string $store): FileSearchConfigurationState
    {
        return $store === null
            ? FileSearchConfigurationState::NotConfigured
            : FileSearchConfigurationState::Configured;
    }

    private function indexingEnabled(): bool
    {
        return (bool) config('gemini_file_search.indexing.enabled', false);
    }

    /**
     * Agregat indeksasi hanya untuk store yang terkonfigurasi sekarang.
     *
     * Baris store lain dan baris tanpa identitas store tetap dilaporkan pada
     * store_scope sebagai riwayat, tetapi tidak pernah masuk ke counts maupun
     * state store aktif. not_recorded dihitung dari dokumen registry yang
     * belum memiliki baris store aktif, bukan dari selisih sembarang, karena
     * sisa selisih itu dapat berasal dari store lain atau percobaan lain.
     *
     * @return array{state: string, message: string, counts: array<string, int>, store_scope: array<string, int>}
     */
    private function indexingSummary(?string $configuredStore, int $documentCount): array
    {
        $stateCounts = array_fill_keys(
            array_map(static fn (GeminiDocumentIndexState $state): string => $state->value, GeminiDocumentIndexState::cases()),
            0,
        );
        $matchingStoreCount = 0;
        $otherStoreCount = 0;
        $unscopedCount = 0;

        $groups = KnowledgeDocumentGeminiIndex::query()->toBase()
            ->selectRaw('state, store_id, COUNT(*) as aggregate_count')
            ->groupBy('state', 'store_id')
            ->get();

        foreach ($groups as $group) {
            $recordCount = (int) $group->aggregate_count;
            $state = GeminiDocumentIndexState::tryFrom((string) $group->state) ?? GeminiDocumentIndexState::Unknown;

            if ($configuredStore !== null && $group->store_id === $configuredStore) {
                // State tak dikenal tetap dihitung sebagai hasil tak pasti agar
                // agregat store aktif tidak pernah terlihat lebih baik daripada
                // kenyataannya.
                $stateCounts[$state->value] += $recordCount;
                $matchingStoreCount += $recordCount;
            } elseif ($group->store_id !== null) {
                // Catatan dengan store lain (atau saat store belum dikonfigurasi)
                // tetap terlihat sebagai riwayat, bukan hasil store aktif.
                $otherStoreCount += $recordCount;
            } else {
                // Tanpa identitas store, catatan tidak dapat dipastikan milik
                // konfigurasi mana pun sehingga tidak dihitung sebagai hasil.
                $unscopedCount += $recordCount;
            }
        }

        $configuredStoreDocumentCount = $configuredStore === null ? 0 : (int) KnowledgeDocumentGeminiIndex::query()->toBase()
            ->where('store_id', $configuredStore)
            ->distinct()
            ->count('knowledge_document_id');

        $counts = [
            'not_recorded' => max(0, $documentCount - $configuredStoreDocumentCount),
            'in_progress' => $this->inProgressCount($stateCounts),
            'completed' => $stateCounts[GeminiDocumentIndexState::Completed->value],
            'failed' => $stateCounts[GeminiDocumentIndexState::Failed->value],
            'unknown' => $stateCounts[GeminiDocumentIndexState::Unknown->value],
        ];
        $state = $this->aggregateIndexingState($counts, $documentCount);

        return [
            'state' => $state->value,
            'message' => $state->message(),
            'counts' => $counts,
            'store_scope' => [
                'records_with_configured_store' => $matchingStoreCount,
                'records_with_other_store' => $otherStoreCount,
                'records_without_store' => $unscopedCount,
            ],
        ];
    }

    /** @param array<string, int> $stateCounts */
    private function inProgressCount(array $stateCounts): int
    {
        $unresolved = [
            GeminiDocumentIndexState::Queued,
            GeminiDocumentIndexState::Uploading,
            GeminiDocumentIndexState::Uploaded,
            GeminiDocumentIndexState::Importing,
            GeminiDocumentIndexState::Pending,
        ];

        return array_sum(array_map(
            static fn (GeminiDocumentIndexState $state): int => $stateCounts[$state->value],
            $unresolved,
        ));
    }

    /** @param array<string, int> $counts */
    private function aggregateIndexingState(array $counts, int $documentCount): GeminiIndexingAggregateState
    {
        return match (true) {
            ! $this->indexingEnabled() => GeminiIndexingAggregateState::Disabled,
            $documentCount === 0 => GeminiIndexingAggregateState::NoDocuments,
            $counts['unknown'] > 0 => GeminiIndexingAggregateState::Unknown,
            $counts['in_progress'] > 0 => GeminiIndexingAggregateState::InProgress,
            $counts['failed'] > 0 => GeminiIndexingAggregateState::Failed,
            $counts['not_recorded'] === $documentCount => GeminiIndexingAggregateState::NotStarted,
            $counts['not_recorded'] > 0 => GeminiIndexingAggregateState::Partial,
            $counts['completed'] > 0 => GeminiIndexingAggregateState::Completed,
            default => GeminiIndexingAggregateState::NotStarted,
        };
    }

    /** @return array{total: int, published: int, unpublished: int} */
    private function knowledgeDocumentSummary(): array
    {
        $row = KnowledgeDocument::query()->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END), 0) as published')
            ->first();

        $total = (int) ($row->total ?? 0);
        $published = (int) ($row->published ?? 0);

        return ['total' => $total, 'published' => $published, 'unpublished' => $total - $published];
    }

    /**
     * Batasan nyata yang menghalangi operasi AI yang aman, berdasarkan status
     * yang baru saja dibaca. Indeksasi selesai tidak pernah diterjemahkan
     * menjadi siap dipublikasikan atau siap menjawab.
     *
     * @param  array{state: string, message: string}  $chat
     * @param  array{state: string, message: string, counts: array<string, int>, store_scope: array<string, int>}  $indexing
     * @param  array{total: int, published: int, unpublished: int}  $documents
     * @return list<string>
     */
    private function limitations(array $chat, ?string $store, array $indexing, array $documents): array
    {
        $limitations = [];

        if ($chat['state'] !== ChatConfigurationState::Configured->value) {
            $limitations[] = $chat['message'];
        }

        if ($store === null) {
            $limitations[] = FileSearchConfigurationState::NotConfigured->message();
        }

        if ($indexing['state'] === GeminiIndexingAggregateState::Disabled->value) {
            $limitations[] = GeminiIndexingAggregateState::Disabled->message();
        }

        if ($indexing['counts']['unknown'] > 0) {
            $limitations[] = $indexing['counts']['unknown'].' dokumen menunggu pemeriksaan manual karena hasil di Google belum diketahui.';
        }

        if ($indexing['counts']['failed'] > 0) {
            $limitations[] = $indexing['counts']['failed'].' dokumen gagal diindeks.';
        }

        if ($indexing['counts']['in_progress'] > 0) {
            $limitations[] = $indexing['counts']['in_progress'].' dokumen masih dalam proses indeksasi.';
        }

        if ($indexing['counts']['not_recorded'] > 0) {
            $limitations[] = $indexing['counts']['not_recorded'].' dokumen belum dijadwalkan untuk indeksasi Gemini File Search.';
        }

        if ($indexing['store_scope']['records_with_other_store'] > 0) {
            $limitations[] = $indexing['store_scope']['records_with_other_store'].' catatan indeksasi menargetkan store berbeda dari konfigurasi saat ini.';
        }

        if ($indexing['store_scope']['records_without_store'] > 0) {
            $limitations[] = $indexing['store_scope']['records_without_store'].' catatan indeksasi tidak menyimpan identitas store tujuan.';
        }

        if ($indexing['counts']['completed'] > 0 && $documents['published'] === 0) {
            $limitations[] = 'Dokumen yang sudah terindeks belum dipublikasikan sehingga belum dapat menjadi sumber jawaban Assistant.';
        }

        $limitations[] = 'Status ini tidak memverifikasi koneksi live; verifikasi eksplisit tetap diperlukan sebelum operasi AI dipercaya.';

        return $limitations;
    }
}
