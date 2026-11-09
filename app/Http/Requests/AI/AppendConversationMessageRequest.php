<?php

namespace App\Http\Requests\AI;

use App\Services\AI\AiConversationService;
use Illuminate\Foundation\Http\FormRequest;

class AppendConversationMessageRequest extends FormRequest
{
    public function authorize(AiConversationService $conversations): bool
    {
        $conversations->resolveOwned($this->user(), (string) $this->route('conversationId'));

        return true;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.required' => 'Pesan wajib diisi.',
        ];
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'message' => [
                'required', 'string', 'max:10000',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 16_384) {
                        $fail('Pesan tidak boleh melebihi 16.384 byte.');
                    }
                },
            ],
            'request_id' => ['required', 'uuid'],
        ];
    }
}
