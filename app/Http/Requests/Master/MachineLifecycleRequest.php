<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request aksi lifecycle Machine tanpa reason teks wajib dari pengguna.
 *
 * Kontrak V1 yang disetujui Owner: konfirmasi eksplisit pada dialog UI adalah
 * syarat cukup, sehingga tidak ada field reason yang harus diisi. Legalitas
 * transisi dan konsekuensi PM tetap divalidasi oleh MachineLifecycleService.
 */
class MachineLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
