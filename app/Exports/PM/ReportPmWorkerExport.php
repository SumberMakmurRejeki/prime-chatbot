<?php

namespace App\Exports\PM;

use App\Services\Report\ReportPmExportSpool;
use App\Services\Report\ReportPmService;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReportPmWorkerExport implements FromGenerator, WithHeadings, WithMapping
{
    use MapsPmExportRows;

    public function __construct(
        private readonly ReportPmExportSpool $spool,
        private readonly ReportPmService $reportPmService,
    ) {}

    public function generator(): Generator
    {
        $parent = null;

        foreach ($this->spool->records() as $record) {
            if ($record['type'] === 'parent') {
                $parent = $record;

                continue;
            }

            if ($record['type'] !== 'detail' || $parent === null) {
                continue;
            }

            // Merge saat baca: parent membawa kolom identitas PM, detail menang untuk
            // kolom level item seperti is_warning agar warning per item tetap benar.
            yield (object) array_merge($parent, $record);
        }
    }
}
