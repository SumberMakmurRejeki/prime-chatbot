<?php

namespace App\Http\Requests\Master;

class ApplyPmChecksheetVueCandidateScheduleRequest extends UpdatePmChecksheetVueCandidateRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'token' => ['required', 'string', 'size:64'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
