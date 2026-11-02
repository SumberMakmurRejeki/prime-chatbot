<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Restore mengonsumsi pointer rollback, sehingga permintaan wajib membawa
 * pasangan pointer yang dilihat admin. Input yang kedaluwarsa ditolak sebagai
 * konflik, bukan diam-diam mengembalikan revisi yang sudah berubah.
 */
class RestoreAiProviderConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage_ai');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_active_revision_id' => ['required', 'integer'],
            'expected_previous_revision_id' => ['required', 'integer'],
        ];
    }
}
