<?php

namespace App\Http\Requests\Master;

use App\Models\PmAction;
use App\Services\PM\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePmChecksheetRequest extends FormRequest
{
    protected $redirectRoute = 'master-checksheet.create';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = json_decode((string) $this->input('wizard_payload', '{}'), true);

        if (! is_array($payload)) {
            $payload = [];
        }

        $this->merge([
            'checksheet_code' => strtoupper(trim((string) $this->input('checksheet_code'))),
            'checksheet_name' => trim((string) $this->input('checksheet_name')),
            'description' => trim((string) $this->input('description')),
            'is_active' => filter_var($this->input('is_active', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            'selected_machine_ids' => $payload['selected_machine_ids'] ?? [],
            'parts' => $payload['parts'] ?? [],
            'standards' => $payload['standards'] ?? [],
            'schedule' => $payload['schedule'] ?? [],
            'business_date' => [
                'today' => BusinessDate::today()->toDateString(),
            ],
        ]);
    }

    public function rules(): array
    {
        return [
            'checksheet_code' => ['required', 'string', 'max:50', Rule::unique('pm_checksheets', 'checksheet_code')],
            'checksheet_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'selected_machine_ids' => ['required', 'array', 'min:1'],
            'selected_machine_ids.*' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('machines', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')),
            ],
            'parts' => ['required', 'array'],
            'standards' => ['required', 'array'],
            'schedule' => ['required', 'array'],
            'schedule.frequency_type' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'schedule.operational_from' => ['required', 'date', 'after_or_equal:business_date.today'],
            'schedule.start_date' => ['nullable', 'date'],
            'schedule.generate_until' => ['nullable', 'date'],
            'schedule.weekly_days' => ['nullable', 'array'],
            'schedule.weekly_days.*' => ['integer', 'between:0,6', 'distinct:strict'],
            'schedule.monthly_day' => ['nullable', 'integer', 'between:1,31'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $machineIds = array_map('intval', $this->input('selected_machine_ids', []));
                $parts = $this->input('parts', []);
                $standards = $this->input('standards', []);
                $schedule = $this->input('schedule', []);

                foreach ($machineIds as $machineId) {
                    $machineParts = $parts[$machineId] ?? null;

                    if (! is_array($machineParts) || count($machineParts) < 1) {
                        $validator->errors()->add('wizard_payload', "Mesin {$machineId} wajib memiliki minimal 1 part.");

                        continue;
                    }

                    $partNames = [];

                    foreach ($machineParts as $index => $partRow) {
                        $partName = trim((string) ($partRow['name'] ?? ''));
                        $partKey = (string) ($partRow['id'] ?? '');

                        if ($partName === '') {
                            $validator->errors()->add('wizard_payload', "Part pada mesin {$machineId} wajib diisi.");
                        }

                        if ($partName !== '' && in_array(strtolower($partName), $partNames, true)) {
                            $validator->errors()->add('wizard_payload', "Part duplikat pada mesin {$machineId} tidak diizinkan.");
                        }

                        $partNames[] = strtolower($partName);

                        $partStandards = $standards[$partKey] ?? null;

                        if (! is_array($partStandards) || count($partStandards) < 1) {
                            $validator->errors()->add('wizard_payload', "Part {$partName} wajib memiliki minimal 1 standard.");

                            continue;
                        }

                        foreach ($partStandards as $standardRow) {
                            $standardName = trim((string) ($standardRow['name'] ?? ''));
                            $inputType = (string) ($standardRow['input_type'] ?? '');

                            if ($standardName === '') {
                                $validator->errors()->add('wizard_payload', "Standard pada part {$partName} wajib diisi.");
                            }

                            if (! in_array($inputType, ['action', 'number', 'range'], true)) {
                                $validator->errors()->add('wizard_payload', "Tipe input standard pada part {$partName} tidak valid.");
                            }

                            if (array_key_exists('selection_mode', $standardRow)
                                && $standardRow['selection_mode'] !== null
                                && ($inputType !== 'action' || ! in_array($standardRow['selection_mode'], ['single', 'multiple'], true))
                            ) {
                                $validator->errors()->add('wizard_payload', "Selection mode Standard pada part {$partName} tidak valid.");
                            }

                            if ($inputType === 'action') {
                                $actionIds = $standardRow['action_ids'] ?? null;
                                $actionIdsProvided = array_key_exists('action_ids', $standardRow);

                                if ($actionIdsProvided) {
                                    // action_ids eksplisit harus deretan integer unik
                                    // berurutan; validasi duplikat/integer dikerjakan
                                    // manual agar pesan error tetap spesifik.
                                    if (! is_array($actionIds) || count($actionIds) < 1) {
                                        $validator->errors()->add('wizard_payload', "Standard action pada part {$partName} wajib memilih minimal 1 Master Action.");
                                    } elseif (count($actionIds) !== count(array_unique(array_map('intval', $actionIds)))) {
                                        $validator->errors()->add('wizard_payload', "Master Action pada part {$partName} tidak boleh duplikat.");
                                    } else {
                                        $invalidActionId = false;
                                        foreach ($actionIds as $actionId) {
                                            if (! is_int($actionId) && ! (is_string($actionId) && ctype_digit($actionId))) {
                                                $invalidActionId = true;
                                                break;
                                            }
                                        }

                                        if ($invalidActionId) {
                                            $validator->errors()->add('wizard_payload', "ID Master Action pada part {$partName} tidak valid.");
                                        } else {
                                            if ($this->requiresActiveActionIds()) {
                                                $activeCount = PmAction::query()
                                                    ->whereIn('id', array_map('intval', $actionIds))
                                                    ->where('is_active', true)
                                                    ->count();
                                                if ($activeCount !== count($actionIds)) {
                                                    $validator->errors()->add('wizard_payload', "Master Action pada part {$partName} tidak valid atau sudah nonaktif.");
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    // Payload legacy tanpa action_ids tetap divalidasi
                                    // via action_options agar wizard lama tidak rusak.
                                    $options = array_filter(array_map('trim', is_array($standardRow['action_options'] ?? null) ? $standardRow['action_options'] : []));
                                    if (count($options) < 1) {
                                        $validator->errors()->add('wizard_payload', "Standard action pada part {$partName} wajib punya minimal 1 opsi.");
                                    }
                                }
                            }

                            if ($inputType === 'number' && ($standardRow['target_value'] ?? null) === null) {
                                $validator->errors()->add('wizard_payload', "Standard number pada part {$partName} wajib punya target value.");
                            }

                            if ($inputType === 'range') {
                                $minValue = $standardRow['min_value'] ?? null;
                                $maxValue = $standardRow['max_value'] ?? null;

                                if ($minValue === null || $maxValue === null) {
                                    $validator->errors()->add('wizard_payload', "Standard range pada part {$partName} wajib punya min dan max.");
                                } elseif ((float) $minValue > (float) $maxValue) {
                                    $validator->errors()->add('wizard_payload', "Min value tidak boleh lebih besar dari max value pada part {$partName}.");
                                }
                            }
                        }
                    }
                }

                if (($schedule['frequency_type'] ?? null) === 'weekly' && count($schedule['weekly_days'] ?? []) < 1) {
                    $validator->errors()->add('wizard_payload', 'Frekuensi mingguan wajib memilih minimal 1 hari.');
                }

                if (($schedule['frequency_type'] ?? null) === 'monthly' && empty($schedule['monthly_day'])) {
                    $validator->errors()->add('wizard_payload', 'Frekuensi bulanan wajib mengisi tanggal (1-31).');
                }
            },
        ];
    }

    /**
     * Pada edit, kelayakan Action nonaktif bergantung pada pivot Standard yang
     * terkunci dan diperiksa kembali oleh service di dalam transaksi.
     */
    protected function requiresActiveActionIds(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'checksheet_code.required' => 'Kode checksheet wajib diisi.',
            'checksheet_code.unique' => 'Kode checksheet sudah dipakai.',
            'checksheet_name.required' => 'Nama checksheet wajib diisi.',
            'selected_machine_ids.required' => 'Minimal pilih 1 mesin.',
            'selected_machine_ids.*.exists' => 'Mesin tidak valid atau sudah nonaktif.',
            'schedule.frequency_type.required' => 'Frekuensi jadwal wajib diisi.',
            'schedule.operational_from.required' => 'Tanggal mulai jadwal PRIME wajib diisi.',
            'schedule.operational_from.after_or_equal' => 'Tanggal mulai jadwal PRIME tidak boleh sebelum tanggal bisnis hari ini.',
        ];
    }
}
