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
        $rawPayload = $this->input('wizard_payload', '{}');
        $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : null;
        $payloadIsValid = is_array($payload)
            && array_diff(array_keys($payload), ['selected_machine_ids', 'parts', 'standards', 'schedule']) === [];

        if (! $payloadIsValid) {
            $payload = [];
        }

        $descriptionInput = $this->input('description');

        $this->merge([
            'checksheet_code' => is_string($this->input('checksheet_code')) ? strtoupper(trim($this->input('checksheet_code'))) : $this->input('checksheet_code'),
            'checksheet_name' => is_string($this->input('checksheet_name')) ? trim($this->input('checksheet_name')) : $this->input('checksheet_name'),
            'description' => is_string($descriptionInput) ? trim($descriptionInput) : $descriptionInput,
            'wizard_payload_valid' => $payloadIsValid,
            'selected_machine_ids' => $payload['selected_machine_ids'] ?? null,
            'parts' => $payload['parts'] ?? null,
            'standards' => $payload['standards'] ?? null,
            'schedule' => $payload['schedule'] ?? null,
            'business_date' => ['today' => BusinessDate::today()->toDateString()],
        ]);

        // Nilai status yang tidak dikirim dibiarkan absen; pemanggil memutuskan
        // default (create aktif, update mempertahankan nilai tersimpan).
        if ($this->exists('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'checksheet_code' => ['required', 'string', 'max:50', Rule::unique('pm_checksheets', 'checksheet_code')],
            'checksheet_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'wizard_payload_valid' => ['accepted'],
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
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $machineIds = $this->input('selected_machine_ids');
                $parts = $this->input('parts');
                $standards = $this->input('standards');
                $schedule = $this->input('schedule');

                if (! is_array($machineIds) || ! is_array($parts) || ! is_array($standards) || ! is_array($schedule)) {
                    $validator->errors()->add('wizard_payload', 'Struktur data checksheet tidak valid. Silakan periksa kembali isian.');

                    return;
                }

                // Payload tidak boleh memuat part untuk mesin yang tidak dipilih.
                $selectedMachineKeys = array_map('strval', $machineIds);
                foreach (array_keys($parts) as $machineKey) {
                    if (! in_array((string) $machineKey, $selectedMachineKeys, true)) {
                        $validator->errors()->add('wizard_payload.parts', 'Payload part memuat mesin yang tidak dipilih.');
                    }
                }

                $partIds = [];

                foreach ($machineIds as $machineId) {
                    $machineParts = $parts[$machineId] ?? null;

                    if (! is_array($machineParts) || $machineParts === []) {
                        $validator->errors()->add('wizard_payload.parts', "Mesin {$machineId} wajib memiliki minimal 1 part.");

                        continue;
                    }

                    $partNames = [];
                    foreach ($machineParts as $partRow) {
                        if (! is_array($partRow)
                            || array_diff(array_keys($partRow), ['id', 'name', 'description']) !== []
                            || ! is_string($partRow['name'] ?? null)
                            || ! is_string($partRow['id'] ?? null)
                        ) {
                            $validator->errors()->add('wizard_payload.parts', "Struktur part pada mesin {$machineId} tidak valid.");

                            continue;
                        }

                        $partName = trim($partRow['name']);
                        $partKey = $partRow['id'];
                        if ($partKey === '' || in_array($partKey, $partIds, true)) {
                            $validator->errors()->add('wizard_payload.parts', 'ID part kosong atau ambigu.');
                        }
                        $partIds[] = $partKey;
                        if (isset($partRow['description']) && ! is_string($partRow['description'])) {
                            $validator->errors()->add('wizard_payload.parts', "Deskripsi part {$partName} tidak valid.");
                        }
                        if (mb_strlen($partName) > 100) {
                            $validator->errors()->add('wizard_payload.parts', "Nama part pada mesin {$machineId} melebihi 100 karakter.");
                        }
                        if ($partName === '') {
                            $validator->errors()->add('wizard_payload.parts', "Part pada mesin {$machineId} wajib diisi.");
                        }
                        if ($partName !== '' && in_array(mb_strtolower($partName), $partNames, true)) {
                            $validator->errors()->add('wizard_payload.parts', "Part duplikat pada mesin {$machineId} tidak diizinkan.");
                        }
                        $partNames[] = mb_strtolower($partName);

                        $partStandards = $standards[$partKey] ?? null;
                        if (! is_array($partStandards) || $partStandards === []) {
                            $validator->errors()->add('wizard_payload.standards', "Part {$partName} wajib memiliki minimal 1 standard.");

                            continue;
                        }

                        $standardNames = [];
                        foreach ($partStandards as $standardRow) {
                            $allowedAttributes = ['name', 'input_type', 'selection_mode', 'action_options', 'action_ids', 'target_value', 'min_value', 'max_value', 'unit', 'description', 'is_required', 'is_active'];
                            if (! is_array($standardRow)
                                || array_diff(array_keys($standardRow), $allowedAttributes) !== []
                                || ! is_string($standardRow['name'] ?? null)
                                || ! is_string($standardRow['input_type'] ?? null)
                            ) {
                                $validator->errors()->add('wizard_payload.standards', "Struktur standard pada part {$partName} tidak valid.");

                                continue;
                            }

                            $standardName = trim($standardRow['name']);
                            $inputType = $standardRow['input_type'];
                            if ($standardName === '') {
                                $validator->errors()->add('wizard_payload.standards', "Standard pada part {$partName} wajib diisi.");
                            }

                            // Kolom standard_name dibatasi 150 karakter; tanpa
                            // batas ini MySQL strict mode gagal dengan 500.
                            if (mb_strlen($standardName) > 150) {
                                $validator->errors()->add('wizard_payload.standards', "Nama standard pada part {$partName} melebihi 150 karakter.");
                            }
                            $normalizedName = mb_strtolower($standardName);
                            if ($normalizedName !== '' && in_array($normalizedName, $standardNames, true)) {
                                $validator->errors()->add('wizard_payload.standards', "Nama standard duplikat pada part {$partName} tidak diizinkan.");
                            }
                            $standardNames[] = $normalizedName;

                            if (! in_array($inputType, ['action', 'number', 'range'], true)) {
                                $validator->errors()->add('wizard_payload.standards', "Tipe input standard pada part {$partName} tidak valid.");

                                continue;
                            }

                            if (array_key_exists('selection_mode', $standardRow)
                                && $standardRow['selection_mode'] !== null
                                && ($inputType !== 'action' || ! in_array($standardRow['selection_mode'], ['single', 'multiple'], true))
                            ) {
                                $validator->errors()->add('wizard_payload.standards', "Selection mode Standard pada part {$partName} tidak valid.");
                            }

                            foreach (['is_required', 'is_active'] as $booleanField) {
                                if (array_key_exists($booleanField, $standardRow) && ! is_bool($standardRow[$booleanField]) && ! in_array($standardRow[$booleanField], [0, 1, '0', '1'], true)) {
                                    $validator->errors()->add('wizard_payload.standards', "Nilai {$booleanField} pada standard {$standardName} tidak valid.");
                                }
                            }
                            if (isset($standardRow['description']) && ! is_string($standardRow['description'])) {
                                $validator->errors()->add('wizard_payload.standards', "Deskripsi standard {$standardName} tidak valid.");
                            }
                            if (isset($standardRow['unit']) && (! is_string($standardRow['unit']) || mb_strlen($standardRow['unit']) > 30)) {
                                $validator->errors()->add('wizard_payload.standards', "Unit standard {$standardName} harus berupa teks maksimal 30 karakter.");
                            }

                            if ($inputType === 'action') {
                                $actionIds = $standardRow['action_ids'] ?? null;
                                if (array_key_exists('action_ids', $standardRow)) {
                                    // Validasi tipe dijalankan lebih dulu agar nilai
                                    // bersarang tidak memicu warning/konversi PHP.
                                    if (! is_array($actionIds) || $actionIds === []) {
                                        $validator->errors()->add('wizard_payload.standards', "Standard action pada part {$partName} wajib memilih Master Action.");
                                    } else {
                                        $validActionIds = true;
                                        foreach ($actionIds as $actionId) {
                                            if (! is_int($actionId) && (! is_string($actionId) || ! ctype_digit($actionId))) {
                                                $validActionIds = false;
                                                break;
                                            }
                                        }
                                        if (! $validActionIds) {
                                            $validator->errors()->add('wizard_payload.standards', "ID Master Action pada part {$partName} tidak valid.");
                                        } elseif (count($actionIds) !== count(array_unique(array_map('intval', $actionIds)))) {
                                            $validator->errors()->add('wizard_payload.standards', "Master Action pada part {$partName} tidak boleh duplikat.");
                                        } elseif ($this->requiresActiveActionIds() && PmAction::query()->whereIn('id', array_map('intval', $actionIds))->where('is_active', true)->count() !== count($actionIds)) {
                                            $validator->errors()->add('wizard_payload.standards', "Master Action pada part {$partName} tidak valid atau sudah nonaktif.");
                                        }
                                    }
                                } else {
                                    // Payload legacy tanpa action_ids tetap divalidasi
                                    // via action_options agar wizard lama tidak rusak.
                                    $options = is_array($standardRow['action_options'] ?? null) ? $standardRow['action_options'] : [];
                                    $validOptions = array_filter($options, fn ($option): bool => is_string($option) && trim($option) !== '');
                                    if ($validOptions === []) {
                                        $validator->errors()->add('wizard_payload.standards', "Standard action pada part {$partName} wajib punya minimal 1 opsi.");
                                    }
                                }

                                continue;
                            }

                            if ($inputType === 'number') {
                                $this->validateDecimal($validator, $standardRow['target_value'] ?? null, "Standard {$standardName} wajib memiliki target numerik dengan maksimal dua desimal.");
                            } else {
                                $minValue = $standardRow['min_value'] ?? null;
                                $maxValue = $standardRow['max_value'] ?? null;
                                $minValid = $this->validateDecimal($validator, $minValue, "Nilai min pada standard {$standardName} tidak valid.");
                                $maxValid = $this->validateDecimal($validator, $maxValue, "Nilai max pada standard {$standardName} tidak valid.");
                                if ($minValid && $maxValid && $this->decimalToCents((string) $minValue) > $this->decimalToCents((string) $maxValue)) {
                                    $validator->errors()->add('wizard_payload.standards', "Min value tidak boleh lebih besar dari max value pada part {$partName}.");
                                }
                            }
                        }
                    }
                }

                // Peta standards hanya boleh mereferensikan part yang dikirim.
                foreach (array_keys($standards) as $standardsPartKey) {
                    if (! in_array((string) $standardsPartKey, $partIds, true)) {
                        $validator->errors()->add('wizard_payload.standards', 'Payload standard memuat referensi part yang tidak dikenal.');
                    }
                }

                if (($schedule['frequency_type'] ?? null) === 'weekly' && (! is_array($schedule['weekly_days'] ?? null) || $schedule['weekly_days'] === [])) {
                    $validator->errors()->add('wizard_payload.schedule', 'Frekuensi mingguan wajib memilih minimal 1 hari.');
                }
                if (($schedule['frequency_type'] ?? null) === 'monthly' && empty($schedule['monthly_day'])) {
                    $validator->errors()->add('wizard_payload.schedule', 'Frekuensi bulanan wajib mengisi tanggal (1-31).');
                }
            },
        ];
    }

    private function validateDecimal($validator, mixed $value, string $message): bool
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            $validator->errors()->add('wizard_payload.standards', $message);

            return false;
        }

        $decimal = trim((string) $value);
        if (! is_numeric($decimal) || ! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $decimal)) {
            $validator->errors()->add('wizard_payload.standards', $message);

            return false;
        }

        // DECIMAL(12,2) membatasi bagian integer maksimal 10 digit; fraction
        // sudah dibatasi dua digit oleh pola di atas.
        $whole = ltrim(explode('.', ltrim($decimal, '-'))[0], '0');
        if (strlen($whole) > 10) {
            $validator->errors()->add('wizard_payload.standards', $message);

            return false;
        }

        return true;
    }

    private function decimalToCents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

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
            'wizard_payload_valid.accepted' => 'Struktur data checksheet tidak valid. Silakan periksa kembali isian.',
        ];
    }
}
