<x-layouts.app
    title="Edit PM Review"
    heading="PM Management / PM Review"
    :actor-name="$actorName"
    :role="$role"
>
    @php
        $partGroups = $execution->items->groupBy(fn ($item) => (string) ($item->pm_checksheet_part_id ?? '0'));
    @endphp

    <section class="space-y-6">
        <div>
            <h2 class="text-[2rem] font-semibold tracking-[-0.04em] text-[var(--color-prime-ink)]">Edit Hasil Eksekusi PM</h2>
            <p class="mt-1 text-[var(--color-prime-muted)]">Koreksi nilai pengecekan, catatan part, dan isi change note wajib untuk audit.</p>
        </div>

        @if (session('flash_error'))
            <x-ui.alert variant="error">
                {{ session('flash_error') }}
                <a href="{{ route('pm-review.edit', $execution->id) }}" class="ml-1 inline font-bold underline underline-offset-2">Muat Ulang Data Terbaru</a>
            </x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form action="{{ route('pm-review.update', $execution->id) }}" method="POST" class="space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="expected_revision" value="{{ old('expected_revision', (string) $execution->revision) }}">
            @foreach ($partGroups as $partKey => $items)
                @php $first = $items->first(); @endphp
                <x-ui.card>
                    <h3 class="border-b border-[var(--color-prime-border)] pb-2 text-lg font-semibold">{{ $first?->part_name_snapshot }}</h3>
                    <div class="mt-4 space-y-4">
                        @foreach ($items as $item)
                            <div>
                                <label class="mb-1 block text-sm font-semibold">{{ $item->standard_name_snapshot }} <span class="text-xs text-[var(--color-prime-muted)]">({{ $item->input_type_snapshot }})</span></label>
                                @if ($item->input_type_snapshot === 'action')
                                    @if ($item->effectiveSelectionMode() === 'multiple')
                                        @php
                                            $oldActionInputKey = 'items.' . $item->id . '.action_value';
                                            $oldClearInputKey = 'execution_clear.' . $item->id;
                                            $hasOldClearIntent = session()->hasOldInput($oldClearInputKey);
                                            $hasOldActionIntent = session()->hasOldInput($oldActionInputKey)
                                                && is_array(session()->getOldInput($oldActionInputKey));
                                            $selectedActionLabels = $hasOldClearIntent
                                                ? []
                                                : ($hasOldActionIntent
                                                    ? session()->getOldInput($oldActionInputKey)
                                                    : $item->actionResults->pluck('selected_label_snapshot')->all());
                                            $legacyActionValue = ! $hasOldClearIntent && ! $hasOldActionIntent && $item->actionResults->isEmpty() ? $item->action_value : null;
                                            $clearIntentActive = $hasOldClearIntent;
                                        @endphp
                                        <div class="rounded-xl border border-[var(--color-prime-border)] bg-[var(--color-prime-soft)] px-4 py-3" data-review-action-group="{{ $item->id }}">
                                            <p class="text-xs font-semibold text-[var(--color-prime-muted)]">Pilih satu atau lebih hasil Action dari opsi tersimpan.</p>
                                            @if ($legacyActionValue !== null && $legacyActionValue !== '')
                                                <p class="mt-2 text-sm text-[var(--color-prime-muted)]">Hasil tersimpan (mode lama): <span class="font-medium text-[var(--color-prime-ink)]">{{ $legacyActionValue }}</span></p>
                                            @endif
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                @foreach (($item->action_options_snapshot ?? []) as $option)
                                                    @php $frozenOption = (string) $option; @endphp
                                                    <label class="inline-flex">
                                                        <input
                                                            type="checkbox"
                                                            name="items[{{ $item->id }}][action_value][]"
                                                            value="{{ $frozenOption }}"
                                                            data-review-action-choice="{{ $item->id }}"
                                                            class="peer sr-only"
                                                            @checked(in_array($frozenOption, $selectedActionLabels, true))
                                                        >
                                                        <span class="block min-w-[5.3rem] rounded-[0.8rem] border border-[rgba(15,23,42,0.12)] bg-white px-3 py-2.5 text-center text-[13px] font-semibold text-[#0f172a] transition peer-checked:border-[#1A73E8] peer-checked:bg-[#EFF6FF] peer-checked:text-[#1A73E8] peer-checked:shadow-[inset_0_0_0_1px_rgba(26,115,232,0.18)] peer-focus-visible:border-[#1A73E8] peer-focus-visible:ring-4 peer-focus-visible:ring-[#1A73E8]/10">{{ $frozenOption }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            <div class="mt-3">
                                                <input type="hidden" name="execution_clear[{{ $item->id }}]" value="1" data-review-action-clear="{{ $item->id }}" @disabled(! $clearIntentActive)>
                                                <button type="button" data-review-action-clear-button="{{ $item->id }}" class="inline-flex items-center justify-center rounded-lg border border-[var(--color-prime-border)] bg-white px-3 py-2 text-sm font-medium transition hover:bg-[var(--color-prime-warm-white)]">Kosongkan Pilihan</button>
                                            </div>
                                        </div>
                                    @else
                                        <select name="items[{{ $item->id }}][action_value]" class="w-full rounded-xl border border-[var(--color-prime-border)] px-4 py-3">
                                            @foreach (($item->action_options_snapshot ?? []) as $option)
                                                <option value="{{ strtoupper($option) }}" @selected(strtoupper((string) $item->action_value) === strtoupper((string) $option))>{{ strtoupper($option) }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                @else
                                    <input type="number" step="0.01" name="items[{{ $item->id }}][number_value]" value="{{ old('items.' . $item->id . '.number_value', $item->number_value) }}" class="w-full rounded-xl border border-[var(--color-prime-border)] px-4 py-3">
                                    <p class="mt-1 text-xs text-[var(--color-prime-muted)]">
                                        @if ($item->input_type_snapshot === 'number')
                                            Target: {{ (float) $item->target_value_snapshot }} {{ $item->unit_snapshot }}
                                        @else
                                            Range: {{ (float) $item->min_value_snapshot }} - {{ (float) $item->max_value_snapshot }} {{ $item->unit_snapshot }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endforeach

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Catatan Part</label>
                            <textarea name="part_notes[{{ (int) ($first?->pm_checksheet_part_id ?? 0) }}]" rows="2" class="w-full rounded-xl border border-[var(--color-prime-border)] px-4 py-3">{{ old('part_notes.' . (int) ($first?->pm_checksheet_part_id ?? 0), $first?->note) }}</textarea>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach

            <x-ui.card>
                <label class="mb-1 block text-sm font-semibold">Tanggal Submit PM <span class="text-[var(--color-prime-danger)]">*</span></label>
                <input
                    type="datetime-local"
                    name="submitted_at"
                    required
                    value="{{ old('submitted_at', optional($execution->submitted_at)->format('Y-m-d\\TH:i')) }}"
                    class="w-full rounded-xl border border-[var(--color-prime-border)] px-4 py-3"
                >

                <label class="mb-1 block text-sm font-semibold">Review Note (Opsional)</label>
                <textarea name="review_note" rows="3" class="w-full rounded-xl border border-[var(--color-prime-border)] px-4 py-3">{{ old('review_note', $execution->review_note) }}</textarea>

                <label class="mt-4 mb-1 block text-sm font-semibold">Catatan Perubahan (Change Note) <span class="text-[var(--color-prime-danger)]">*</span></label>
                <textarea name="change_note" rows="3" required class="w-full rounded-xl border border-[var(--color-prime-border)] px-4 py-3" placeholder="Contoh: Koreksi typo nilai input suhu.">{{ old('change_note') }}</textarea>
            </x-ui.card>

            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('pm-review.show', $execution->id) }}" class="rounded-xl border border-[var(--color-prime-border)] px-4 py-3 text-sm font-semibold">Batal</a>
                <x-ui.button type="submit">Simpan Perubahan</x-ui.button>
            </div>
        </form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-review-action-group]').forEach((group) => {
        const itemId = group.dataset.reviewActionGroup;
        const choices = group.querySelectorAll(`[data-review-action-choice="${itemId}"]`);
        const clearMarker = group.querySelector(`[data-review-action-clear="${itemId}"]`);
        const clearButton = group.querySelector(`[data-review-action-clear-button="${itemId}"]`);
        const syncMarker = () => {
            clearMarker.disabled = Array.from(choices).some((choice) => choice.checked);
        };

        choices.forEach((choice) => choice.addEventListener('change', syncMarker));
        clearButton.addEventListener('click', () => {
            choices.forEach((choice) => {
                choice.checked = false;
            });
            clearMarker.disabled = false;
        });
    });
});
</script>
    </section>
</x-layouts.app>
