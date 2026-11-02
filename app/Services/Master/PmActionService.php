<?php

namespace App\Services\Master;

use App\Models\PmAction;
use App\Models\PmChecksheetStandard;
use App\Services\Auth\ActivityLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PmActionService
{
    public function __construct(
        protected ActivityLogService $activityLog,
    ) {}

    /**
     * Satu normalisasi dipakai request, wizard inline-add, dan backfill supaya
     * perbedaan case/spasi tidak dapat membuat label Master Action ganda.
     */
    public function normalizeLabel(string $input): string
    {
        return mb_strtoupper(Str::squish($input), 'UTF-8');
    }

    /**
     * Identitas label dibandingkan tanpa mengubah teks legacy yang disimpan.
     *
     * @param  array<int, string>  $labels
     */
    public function hasUniqueLabelIdentities(array $labels): bool
    {
        $seen = [];

        foreach ($labels as $label) {
            $canonical = $this->normalizeLabel($label);
            if (isset($seen[$canonical])) {
                return false;
            }

            $seen[$canonical] = true;
        }

        return true;
    }

    /**
     * Satu-satunya resolusi pilihan multiple terhadap snapshot beku untuk Executor
     * dan Review; Master Action aktif tidak boleh ikut menentukan kebenaran koreksi.
     *
     * Identitas kosong/ambigu/asing tidak boleh ditebak; hasil selalu diurutkan
     * berdasarkan posisi snapshot, bukan urutan kiriman Admin.
     *
     * @param  array<int, string>  $frozenLabels
     * @return array<int, array{selected_label_snapshot: string, selected_label_identity_hash: string, option_position_snapshot: int}>
     *
     * @throws ValidationException
     */
    public function resolveFrozenChoices(array $frozenLabels, mixed $submitted, string $field): array
    {
        if (! is_array($submitted) || $submitted === []) {
            throw ValidationException::withMessages([$field => 'Action multiple memerlukan array pilihan tidak kosong.']);
        }

        $frozen = [];
        foreach ($frozenLabels as $index => $label) {
            $identity = $this->normalizeLabel((string) $label);
            if (isset($frozen[$identity])) {
                $frozen[$identity] = null;
            } elseif (! array_key_exists($identity, $frozen)) {
                $frozen[$identity] = [
                    'selected_label_snapshot' => (string) $label,
                    'selected_label_identity_hash' => hash('sha256', $identity),
                    'option_position_snapshot' => $index + 1,
                ];
            }
        }

        $selected = [];
        $seen = [];
        foreach ($submitted as $label) {
            if (! is_string($label) || $label === '') {
                throw ValidationException::withMessages([$field => 'Pilihan Action multiple tidak valid.']);
            }
            $identity = $this->normalizeLabel($label);
            if (array_key_exists($identity, $seen) || ! isset($frozen[$identity])) {
                throw ValidationException::withMessages([$field => 'Pilihan Action multiple tidak unik atau tidak ada dalam snapshot.']);
            }
            $seen[$identity] = true;
            $selected[] = $frozen[$identity];
        }
        usort($selected, static fn (array $left, array $right): int => $left['option_position_snapshot'] <=> $right['option_position_snapshot']);

        return $selected;
    }

    public function create(Request $request, string $label): PmAction
    {
        $canonical = $this->validatedLabel($label);

        try {
            $action = PmAction::query()->create([
                'action_label' => $canonical,
                'is_active' => true,
            ]);
        } catch (QueryException $exception) {
            if ($this->isLabelUniqueViolation($exception)) {
                throw ValidationException::withMessages(['action_label' => 'Master Action dengan label tersebut sudah tersedia.']);
            }
            throw $exception;
        }

        $this->activityLog->log(
            request: $request,
            moduleName: 'master_action',
            action: 'create',
            description: sprintf('Create Master Action %s', $action->action_label),
            tableName: 'pm_actions',
            recordId: $action->id,
            newValues: $this->auditValues($action),
        );

        return $action;
    }

    public function update(Request $request, PmAction $action, string $label): PmAction
    {
        $canonical = $this->validatedLabel($label);

        return DB::transaction(function () use ($request, $action, $canonical): PmAction {
            $locked = PmAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditValues($locked);

            try {
                $locked->forceFill(['action_label' => $canonical])->save();
            } catch (QueryException $exception) {
                if ($this->isLabelUniqueViolation($exception)) {
                    throw ValidationException::withMessages(['action_label' => 'Master Action dengan label tersebut sudah tersedia.']);
                }
                throw $exception;
            }

            $this->activityLog->log(
                request: $request,
                moduleName: 'master_action',
                action: 'update',
                description: sprintf('Update Master Action %s', $locked->action_label),
                tableName: 'pm_actions',
                recordId: $locked->id,
                oldValues: $oldValues,
                newValues: $this->auditValues($locked),
            );

            return $locked;
        });
    }

    public function activate(Request $request, PmAction $action): PmAction
    {
        return $this->transition($request, $action, true);
    }

    public function deactivate(Request $request, PmAction $action): PmAction
    {
        return DB::transaction(function () use ($request, $action): PmAction {
            // Lock standard terlebih dahulu, lalu action, agar seragam dengan
            // sinkronisasi subset dan Start snapshot pada Package 2/3.
            $standardIds = PmChecksheetStandard::query()
                ->where('input_type', 'action')
                ->whereHas('actions', fn (Builder $query) => $query->whereKey($action->id))
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $locked = PmAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();

            foreach ($standardIds as $standardId) {
                $hasOtherActive = DB::table('pm_checksheet_standard_actions as pcsa')
                    ->join('pm_actions as pa', 'pa.id', '=', 'pcsa.pm_action_id')
                    ->where('pcsa.pm_checksheet_standard_id', $standardId)
                    ->where('pa.id', '!=', $locked->id)
                    ->where('pa.is_active', true)
                    ->exists();
                if (! $hasOtherActive) {
                    throw ValidationException::withMessages([
                        'action' => 'Master Action tidak dapat dinonaktifkan karena menjadi opsi aktif terakhir pada standard action.',
                    ]);
                }
            }

            $oldValues = $this->auditValues($locked);
            $locked->forceFill(['is_active' => false])->save();
            $this->logTransition($request, 'deactivate', $locked, $oldValues);

            return $locked;
        });
    }

    /** @return Builder<PmAction> */
    public function query(string $search = ''): Builder
    {
        return PmAction::query()
            ->when($search !== '', fn (Builder $query) => $query->where('action_label', 'like', "%{$search}%"))
            ->orderBy('action_label');
    }

    protected function transition(Request $request, PmAction $action, bool $isActive): PmAction
    {
        return DB::transaction(function () use ($request, $action, $isActive): PmAction {
            $locked = PmAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditValues($locked);
            $locked->forceFill(['is_active' => $isActive])->save();
            $this->logTransition($request, $isActive ? 'activate' : 'deactivate', $locked, $oldValues);

            return $locked;
        });
    }

    protected function validatedLabel(string $label): string
    {
        $canonical = $this->normalizeLabel($label);
        if ($canonical === '') {
            throw ValidationException::withMessages(['action_label' => 'Label Master Action wajib diisi.']);
        }
        if (mb_strlen($canonical, 'UTF-8') > 50) {
            throw ValidationException::withMessages(['action_label' => 'Label Master Action maksimal 50 karakter.']);
        }

        return $canonical;
    }

    /** @return array{action_label:string,is_active:bool} */
    protected function auditValues(PmAction $action): array
    {
        return [
            'action_label' => (string) $action->action_label,
            'is_active' => (bool) $action->is_active,
        ];
    }

    protected function logTransition(Request $request, string $event, PmAction $action, array $oldValues): void
    {
        $this->activityLog->log(
            request: $request,
            moduleName: 'master_action',
            action: $event,
            description: sprintf('%s Master Action %s', ucfirst($event), $action->action_label),
            tableName: 'pm_actions',
            recordId: $action->id,
            oldValues: $oldValues,
            newValues: $this->auditValues($action),
        );
    }

    protected function isLabelUniqueViolation(QueryException $exception): bool
    {
        $evidence = strtolower(implode(' ', array_map(static fn ($value): string => (string) $value, [
            $exception->getMessage(),
            $exception->getPrevious()?->getMessage(),
            ...($exception->errorInfo ?? []),
        ])));

        // Hanya terjemahkan konflik unik label; error lain (misal nilai terlalu
        // panjang) harus tetap naik apa adanya agar tidak menyesatkan admin.
        $isDuplicate = str_contains($evidence, 'duplicate')
            || str_contains($evidence, 'unique constraint')
            || str_contains($evidence, '23000')
            || (int) ($exception->errorInfo[1] ?? 0) === 1062;

        return $isDuplicate
            && (str_contains($evidence, 'pm_actions_label_unique') || str_contains($evidence, 'action_label'));
    }
}
