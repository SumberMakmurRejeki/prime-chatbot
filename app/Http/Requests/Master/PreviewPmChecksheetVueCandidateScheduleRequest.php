<?php

namespace App\Http\Requests\Master;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PreviewPmChecksheetVueCandidateScheduleRequest extends PreviewScheduleUpdateRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'wizard_payload.selected_machine_ids' => ['required', 'array', 'min:1'],
            'wizard_payload.selected_machine_ids.*' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('machines', 'id')->where(function ($query): void {
                    $query->whereNull('machines.deleted_at')->where(function ($query): void {
                        $query->where('machines.is_active', true)
                            ->orWhereExists(
                                DB::table('pm_checksheet_machines')
                                    ->selectRaw('1')
                                    ->whereColumn('pm_checksheet_machines.machine_id', 'machines.id')
                                    ->where('pm_checksheet_machines.pm_checksheet_id', $this->route('id')),
                            );
                    });
                }),
            ],
        ];
    }
}
