<?php

namespace App\Http\Requests\PM;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePmReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = [];
        foreach ((array) $this->input('items', []) as $itemId => $itemPayload) {
            if (! is_array($itemPayload)) {
                continue;
            }

            $actionValue = $itemPayload['action_value'] ?? null;
            if (is_array($actionValue)) {
                // Multiple Action dikirim sebagai daftar pilihan; jangan dipaksa menjadi
                // string tunggal karena konversi itu menghilangkan bentuk array valid.
                $actionValue = array_values(array_map(
                    static fn (mixed $value): mixed => is_string($value) ? strtoupper(trim($value)) : $value,
                    $actionValue,
                ));
            } else {
                $actionValue = $actionValue !== null ? strtoupper(trim((string) $actionValue)) : null;
            }

            $items[$itemId] = [
                'action_value' => $actionValue,
                'number_value' => $itemPayload['number_value'] ?? null,
            ];
        }

        $partNotes = [];
        foreach ((array) $this->input('part_notes', []) as $partId => $value) {
            $partNotes[$partId] = trim((string) $value);
        }

        $submittedAt = trim((string) $this->input('submitted_at', ''));

        $this->merge([
            'items' => $items,
            'part_notes' => $partNotes,
            'submitted_at' => $submittedAt !== '' ? str_replace('T', ' ', $submittedAt) : null,
            'change_note' => trim((string) $this->input('change_note', '')),
            'review_note' => $this->has('review_note') ? trim((string) $this->input('review_note')) : null,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'execution_clear' => ['nullable', 'array'],
            'execution_clear.*' => ['in:1'],
            'items' => ['nullable', 'array'],
            'items.*.action_value' => [
                'nullable',
                // Array = hasil Action multiple; scalar tetap divalidasi seperti Action single.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value)) {
                        foreach ($value as $choice) {
                            if (! is_string($choice) || trim($choice) === '' || mb_strlen($choice, 'UTF-8') > 50) {
                                $fail('Pilihan Action multiple tidak valid.');

                                return;
                            }
                        }

                        return;
                    }
                    if (! is_string($value) || mb_strlen($value, 'UTF-8') > 50) {
                        $fail('Nilai action tidak valid.');
                    }
                },
            ],
            'items.*.number_value' => ['nullable', 'numeric'],
            'part_notes' => ['nullable', 'array'],
            'part_notes.*' => ['nullable', 'string'],
            'submitted_at' => ['required', 'date'],
            'change_note' => ['required', 'string', 'max:1000'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expected_revision.required' => 'Versi data PM tidak tersedia. Silakan muat ulang data terbaru sebelum menyimpan.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'submitted_at' => 'tanggal submit PM',
        ];
    }
}
