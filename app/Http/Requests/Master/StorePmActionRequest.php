<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class StorePmActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['action_label' => (string) $this->input('action_label', '')]);
    }

    public function rules(): array
    {
        return ['action_label' => ['required', 'string', 'max:50']];
    }
}
