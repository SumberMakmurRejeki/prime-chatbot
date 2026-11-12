<?php

namespace App\Exports\PM;

use App\Exports\Concerns\BindsLiteralTextCells;
use App\Services\Report\ReportPmService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\DefaultValueBinder;

class ReportPmExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithHeadings, WithMapping
{
    use BindsLiteralTextCells;
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
