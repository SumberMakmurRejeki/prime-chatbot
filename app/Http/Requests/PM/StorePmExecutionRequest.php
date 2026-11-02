<?php

namespace App\Http\Requests\PM;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePmExecutionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Bentuk Action harus tetap utuh agar array multiple dan key yang absen tidak menjadi scalar/CLEAR.
        $normalizedNotes = [];
        foreach ((array) $this->input('part_notes', []) as $partId => $value) {
            $normalizedNotes[$partId] = trim((string) $value);
        }

        $this->merge([
            'part_notes' => $normalizedNotes,
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>|string>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'execution_action' => ['nullable', 'array'],
            'execution_action.*' => ['nullable'],
            'execution_clear' => ['nullable', 'array'],
            'execution_clear.*' => ['in:1'],
            'execution_number' => ['nullable', 'array'],
            'execution_number.*' => ['nullable', 'numeric'],
            'part_notes' => ['nullable', 'array'],
            'part_notes.*' => ['nullable', 'string'],
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
}
