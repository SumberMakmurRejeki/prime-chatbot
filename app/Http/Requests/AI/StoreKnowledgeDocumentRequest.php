<?php

namespace App\Http\Requests\AI;

use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_ai') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'version' => ['required', 'string', 'max:64', 'regex:/^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'document' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:20480'],
        ];
    }
}
