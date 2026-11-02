<?php

namespace App\Exports\PM;

use App\Services\Report\ReportPmService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReportPmExport implements FromCollection, WithHeadings, WithMapping
{
    use MapsPmExportRows;

    /**
     * @param  Collection<int, object>  $rows
     */
    public function __construct(
        protected Collection $rows,
        protected ReportPmService $reportPmService,
    ) {}

    public function collection(): Collection
    {
        return $this->rows
            ->filter(fn (object $row): bool => $row->execution_item_id !== null)
            ->values();
    }
}
