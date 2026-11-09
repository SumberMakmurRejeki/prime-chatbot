<?php

namespace App\Http\Requests\Settings;

use App\Models\AiProviderConfiguration;
use App\Models\AiProviderConfigurationRevision;
use App\Services\AI\AiEndpointPinner;
use App\Services\AI\ProbeTransportPolicy;
use App\Services\AI\UnsafeEndpointException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiProviderConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_ai') ?? false;
    }

    /**
     * Jenis provider selalu disertakan ke validasi: konfigurasi baru memakai
     * driver default, sedangkan revisi memakai driver konfigurasi yang ada
     * sehingga permintaan tanpa field driver pun tidak pernah mengubahnya.
     */
    protected function prepareForValidation(): void
    {
        if ($this->exists('driver')) {
            return;
        }

        $configuration = $this->route('configuration');

        $this->merge([
            'driver' => $configuration instanceof AiProviderConfiguration
                ? $this->existingDriver($configuration) ?? AiProviderConfigurationRevision::DEFAULT_DRIVER
                : AiProviderConfigurationRevision::DEFAULT_DRIVER,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Driver hanya boleh dari daftar resmi PRIME; nilai lain ditolak
            // sebelum menyentuh service penyimpanan.
            'driver' => ['required', 'string', Rule::in(AiProviderConfigurationRevision::SUPPORTED_DRIVERS)],
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

    /**
     * Jenis provider terkunci pada konfigurasi yang sudah ada: mengganti
     * provider memerlukan konfigurasi baru, bukan revisi.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $configuration = $this->route('configuration');

                if (! $configuration instanceof AiProviderConfiguration) {
                    return;
                }

                if ($this->input('driver') !== $this->existingDriver($configuration)) {
                    $validator->errors()->add('driver', 'Jenis provider tidak dapat diubah pada konfigurasi yang sudah ada. Buat konfigurasi baru untuk jenis provider lain.');
                }
            },
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

    private function existingDriver(AiProviderConfiguration $configuration): ?string
    {
        return $configuration->revisions()->orderByDesc('revision_number')->value('driver');
    }
}
