<?php

namespace App\Http\Requests\Settings;

use App\Services\AI\AiEndpointPinner;
use App\Services\AI\ProbeTransportPolicy;
use App\Services\AI\UnsafeEndpointException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAiProviderConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_ai') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'in:chat,embedding'],
            'model_id' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'url:https', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                // Sintaks endpoint aman (HTTPS, host publik, tanpa userinfo/query)
                // divalidasi ulang di sisi server; input Admin tidak boleh
                // melewati kebijakan transport AI-1B2.
                try {
                    (new ProbeTransportPolicy(new AiEndpointPinner))->validateBaseUrl((string) $value);
                } catch (UnsafeEndpointException) {
                    $fail('Base URL ditolak oleh kebijakan endpoint aman.');
                }
            }],
            'credential' => ['nullable', 'string', 'max:10000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (in_array($value, ['Credential tersimpan', '••••••••', '********'], true)) {
                    $fail('Masukkan credential baru atau kosongkan untuk menggunakan credential tersimpan.');
                }
            }],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->replace(array_diff_key($this->all(), ['credential' => true]));

        parent::failedValidation($validator);
    }

    protected function passedValidation(): void
    {
        if (blank($this->input('credential'))) {
            $this->merge(['credential' => null]);
        }
    }
}
