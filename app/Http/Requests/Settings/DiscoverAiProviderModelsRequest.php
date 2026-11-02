<?php

namespace App\Http\Requests\Settings;

use App\Models\AiProviderConfiguration;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\ProbeTransportPolicy;
use App\Services\AI\UnsafeEndpointException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoverAiProviderModelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_ai') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'string', 'url:https', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    (new ProbeTransportPolicy(new AiEndpointPinner))->validateBaseUrl((string) $value);
                } catch (UnsafeEndpointException) {
                    $fail('Base URL tidak aman.');
                }
            }],
            'credential' => ['nullable', 'string', 'max:10000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (in_array($value, ['Credential tersimpan', '••••••••', '********'], true)) {
                    $fail('Masukkan credential baru atau kosongkan untuk menggunakan credential tersimpan.');
                }
            }],
            // Credential tersimpan hanya boleh dipakai ulang ketika penyedia
            // dan revisinya dapat di-resolve secara unik; pasangan tidak valid
            // ditolak fail-closed.
            'provider_id' => ['nullable', 'required_with:revision_id', 'integer', Rule::exists(AiProviderConfiguration::class, 'id')],
            'revision_id' => ['nullable', 'required_with:provider_id', 'integer'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->replace(array_diff_key($this->all(), ['credential' => true]));

        parent::failedValidation($validator);
    }
}
