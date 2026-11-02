<?php

namespace App\Services\Report;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\PM\BusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class ReportPmExportRegistrationService
{
    /**
     * @var list<string>
     */
    private const FILTER_KEYS = [
        'start_date',
        'end_date',
        'location_id',
        'machine_id',
        'part_name',
        'pic_operator',
        'pic_admin',
        'status',
        'warning',
        'search',
    ];

    /**
     * Mendaftarkan intent export setelah filter lolos validasi request.
     *
     * @param  array<string, mixed>  $validatedFilters  Filter ternormalisasi dari ReportPmRequest.
     */
    public function register(User $requester, string $fileType, array $validatedFilters): ReportExport
    {
        if ($requester->role !== 'admin') {
            throw new AuthorizationException('Hanya Admin yang boleh mendaftarkan export PM.');
        }

        if (! in_array($fileType, ['pdf', 'excel'], true)) {
            throw new InvalidArgumentException("Format export PM tidak didukung: {$fileType}.");
        }

        $filters = array_replace(
            array_fill_keys(self::FILTER_KEYS, null),
            Arr::only($validatedFilters, self::FILTER_KEYS),
        );
        $scope = $this->resolveScope($filters);

        return ReportExport::query()->create([
            'report_type' => 'pm',
            'file_type' => $fileType,
            'status' => 'processing',
            'filter_data' => $filters + ['scope' => $scope],
            'requested_by' => $requester->id,
            'requested_by_name_snapshot' => $requester->name,
            'requested_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{period_mode:string, resolved_start_date:?string, resolved_end_date:?string, include_attention_backlog:bool}
     */
    private function resolveScope(array $filters): array
    {
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        if ($startDate !== null || $endDate !== null) {
            return [
                'period_mode' => 'explicit',
                'resolved_start_date' => $startDate,
                'resolved_end_date' => $endDate,
                'include_attention_backlog' => false,
            ];
        }

        $today = BusinessDate::today();

        return [
            'period_mode' => 'default_month',
            'resolved_start_date' => $today->copy()->startOfMonth()->toDateString(),
            'resolved_end_date' => $today->copy()->endOfMonth()->toDateString(),
            'include_attention_backlog' => true,
        ];
    }
}
