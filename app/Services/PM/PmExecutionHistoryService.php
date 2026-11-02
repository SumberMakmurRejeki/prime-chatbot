<?php

namespace App\Services\PM;

use App\Models\PmExecution;
use App\Models\PmExecutionHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PmExecutionHistoryService
{
    public function record(PmExecution $execution, ?User $admin, string $fieldName, ?string $oldValue, ?string $newValue, string $changeNote): void
    {
        PmExecutionHistory::query()->create([
            'pm_execution_id' => $execution->id,
            'changed_by' => $admin?->id,
            'changed_at' => now(),
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_note' => $changeNote,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{field_name:string,old_value:?string,new_value:?string,change_note:string}>  $changes
     */
    public function recordMany(PmExecution $execution, ?User $admin, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $now = now();
        $rows = Collection::make($changes)
            ->map(fn (array $change): array => [
                'pm_execution_id' => $execution->id,
                'changed_by' => $admin?->id,
                'changed_at' => $now,
                'field_name' => $change['field_name'],
                'old_value' => $change['old_value'],
                'new_value' => $change['new_value'],
                'change_note' => $change['change_note'],
                'created_at' => $now,
            ])
            ->all();

        PmExecutionHistory::query()->insert($rows);
    }

    /**
     * Konvensi identitas histori hasil item execution yang dipakai bersama oleh
     * Executor (Operator) dan Admin Review: kunci utamanya adalah ID Part/Standard
     * beku milik execution, bukan nama master, dengan fallback ID item execution.
     *
     * @param  'result'|'part_note'  $kind
     */
    public function formatHistoryFieldName(string $kind, ?int $partId, ?int $standardId, ?int $executionItemId, ?string $partNameSnapshot, ?string $standardNameSnapshot): string
    {
        $prefix = match ($kind) {
            'result' => $partId !== null && $standardId !== null
                ? sprintf('Hasil PM [P%d/S%d]', $partId, $standardId)
                : sprintf('Hasil PM [I%d]', $executionItemId ?? throw ValidationException::withMessages(['execution' => 'Identitas item execution tidak tersedia untuk histori.'])),
            'part_note' => $partId !== null
                ? sprintf('Catatan Part [P%d]', $partId)
                : sprintf('Catatan Part [I%d]', $executionItemId ?? throw ValidationException::withMessages(['execution' => 'Identitas item execution tidak tersedia untuk histori.'])),
            default => throw new \InvalidArgumentException('Unknown Operator history field kind.'),
        };

        $label = $kind === 'part_note'
            ? Str::squish((string) $partNameSnapshot)
            : Str::squish(collect([$partNameSnapshot, $standardNameSnapshot])->filter(fn ($value): bool => filled($value))->implode(' / '));
        // Kolom field_name VARCHAR(100): label dipotong agar prefix identitas beku tidak pernah hilang.
        $remaining = 100 - Str::length($prefix) - 1;
        if ($label === '' || $remaining < 1) {
            return $prefix;
        }
        if (Str::length($label) > $remaining) {
            $label = Str::substr($label, 0, $remaining - 1).'…';
        }
        $fieldName = $prefix.' '.$label;
        if (Str::length($fieldName) > 100) {
            throw ValidationException::withMessages(['execution' => 'Nama field histori melebihi batas penyimpanan.']);
        }

        return $fieldName;
    }
}
