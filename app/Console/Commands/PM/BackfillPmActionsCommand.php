<?php

namespace App\Console\Commands\PM;

use App\Models\PmAction;
use App\Models\PmChecksheetStandard;
use App\Services\Master\PmActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backfill Master Action dari JSON legacy action_options pada standard action.
 *
 * Kontrak:
 * - Tanpa --apply: preflight read-only, keluaran JSON per baris.
 * - Dengan --apply: seluruh preflight dijalankan dulu; satu pun standard invalid
 *   (opsi kosong, entri bukan string, label >50, atau JSON rusak) membatalkan
 *   seluruh penulisan. Tidak ada penulisan parsial lintas standard.
 * - JSON legacy action_options tidak pernah diubah; pivot adalah source of truth baru.
 */
class BackfillPmActionsCommand extends Command
{
    protected $signature = 'pm:backfill-master-actions {--apply : Persist backfill instead of dry-run preflight}';

    protected $description = 'Backfill Master Action rows and standard mappings from legacy action_options JSON';

    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly PmActionService $actionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $standardIds = PmChecksheetStandard::query()
            ->where('input_type', 'action')
            ->orderBy('id')
            ->pluck('id');

        $plans = [];
        $invalid = [];
        $seenLabels = [];
        $mappings = 0;

        foreach ($standardIds->chunk(self::CHUNK_SIZE) as $chunk) {
            $standards = PmChecksheetStandard::query()
                ->whereKey($chunk->all())
                ->orderBy('id')
                ->get(['id', 'action_options']);

            foreach ($standards as $standard) {
                $raw = $standard->getRawOriginal('action_options');
                $decoded = null;
                if (is_string($raw)) {
                    try {
                        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        $decoded = null;
                    }
                } elseif (is_array($raw)) {
                    $decoded = $raw;
                }

                if (! is_array($decoded)) {
                    $invalid[] = ['standard_id' => $standard->id, 'reason' => 'action_options_bukan_array_json_valid'];

                    continue;
                }

                $canonicalByValue = [];
                $positions = [];
                foreach (array_values($decoded) as $index => $entry) {
                    if (! is_string($entry)) {
                        $invalid[] = ['standard_id' => $standard->id, 'reason' => 'entri_bukan_string', 'index' => $index];

                        continue 2;
                    }
                    $canonical = $this->actionService->normalizeLabel($entry);
                    if ($canonical === '') {
                        $invalid[] = ['standard_id' => $standard->id, 'reason' => 'entri_kosong', 'index' => $index];

                        continue 2;
                    }
                    if (mb_strlen($canonical, 'UTF-8') > 50) {
                        $invalid[] = ['standard_id' => $standard->id, 'reason' => 'label_lebih_dari_50_karakter', 'index' => $index];

                        continue 2;
                    }
                    // Nilai kanonik yang sama dalam satu standard collapse ke satu
                    // pivot pada posisi kemunculan pertama.
                    if (! isset($canonicalByValue[$canonical])) {
                        $canonicalByValue[$canonical] = true;
                        $positions[] = ['canonical' => $canonical, 'position' => count($positions) + 1];
                    }
                }

                if ($positions === []) {
                    $invalid[] = ['standard_id' => $standard->id, 'reason' => 'tidak_ada_opsi_kanonik'];

                    continue;
                }

                foreach ($positions as $entry) {
                    $seenLabels[$entry['canonical']] = true;
                    $mappings++;
                }
                $plans[] = ['standard_id' => $standard->id, 'entries' => $positions];
            }
        }

        $distinctLabels = array_keys($seenLabels);
        sort($distinctLabels, SORT_STRING);

        $this->line((string) json_encode([
            'mode' => $apply ? 'apply' : 'dry-run',
            'action_standards' => $standardIds->count(),
            'planned_standards' => count($plans),
            'distinct_canonical_labels' => count($distinctLabels),
            'planned_mappings' => $mappings,
            'invalid_standards' => count($invalid),
        ], JSON_THROW_ON_ERROR));

        foreach ($invalid as $row) {
            $this->line((string) json_encode(['invalid' => $row], JSON_THROW_ON_ERROR));
        }

        if ($invalid !== []) {
            $this->warn('Preflight menemukan standard invalid; --apply menolak menulis apa pun sampai data diperbaiki.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry-run selesai. Gunakan --apply untuk menulis pivot.');

            return self::SUCCESS;
        }

        if ($plans === []) {
            $this->info('Tidak ada standard action untuk dibackfill.');

            return self::SUCCESS;
        }

        $actionIdsByLabel = [];

        foreach ($plans as $plan) {
            try {
                DB::transaction(function () use ($plan, &$actionIdsByLabel): void {
                    $standardLocked = PmChecksheetStandard::query()
                        ->whereKey($plan['standard_id'])
                        ->lockForUpdate()
                        ->first();
                    if (! $standardLocked) {
                        return;
                    }

                    $now = now();
                    // Standard dikunci sebelum mapping ditulis. Hapus/reinsert
                    // seluruh subset agar unique position tidak bentrok ketika
                    // urutan legacy berubah; JSON legacy tetap untouched.
                    DB::table('pm_checksheet_standard_actions')
                        ->where('pm_checksheet_standard_id', $plan['standard_id'])
                        ->delete();
                    foreach ($plan['entries'] as $entry) {
                        $label = $entry['canonical'];
                        if (! isset($actionIdsByLabel[$label])) {
                            try {
                                $created = PmAction::query()->create(['action_label' => $label, 'is_active' => true]);
                                $actionIdsByLabel[$label] = $created->id;
                            } catch (Throwable $exception) {
                                // Unik bersifat backstop concurrent; fallback ke baris pemenang.
                                $existing = PmAction::query()->where('action_label', $label)->value('id');
                                if ($existing === null) {
                                    throw $exception;
                                }
                                $actionIdsByLabel[$label] = $existing;
                            }
                        }

                        DB::table('pm_checksheet_standard_actions')->updateOrInsert(
                            ['pm_checksheet_standard_id' => $plan['standard_id'], 'pm_action_id' => $actionIdsByLabel[$label]],
                            ['position' => $entry['position'], 'created_at' => $now, 'updated_at' => $now],
                        );
                    }
                });
            } catch (Throwable $exception) {
                $this->error(sprintf('Gagal standard_id=%d: %s', $plan['standard_id'], $exception->getMessage()));

                return self::FAILURE;
            }
        }

        $this->info(sprintf('Backfill selesai: %d standard, %d mapping.', count($plans), $mappings));

        return self::SUCCESS;
    }
}
