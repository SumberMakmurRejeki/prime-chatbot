<?php

namespace App\Http\Requests\Master;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdatePmChecksheetVueCandidateRequest extends UpdatePmChecksheetRequest
{
    protected $redirectRoute = 'master-checksheet.vue-candidate.edit';

    public function rules(): array
    {
        $rules = parent::rules();
        $checksheetId = (int) $this->route('id');
        $rules['selected_machine_ids.*'] = [
            'required',
            'integer',
            'distinct:strict',
            Rule::exists('machines', 'id')->where(
                fn ($query) => $query->whereNull('machines.deleted_at')->where(function ($query) use ($checksheetId): void {
                    $query->where('machines.is_active', true)
                        ->orWhereExists(DB::table('pm_checksheet_machines')
                            ->selectRaw('1')
                            ->whereColumn('pm_checksheet_machines.machine_id', 'machines.id')
                            ->where('pm_checksheet_machines.pm_checksheet_id', $checksheetId));
                }),
            ),
        ];

        return $rules;
    }
}
