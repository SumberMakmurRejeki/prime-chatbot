<?php

namespace App\Jobs;

use App\Services\Report\ReportPmExportWorker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePmReportExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $reportExportId,
    ) {}

    public function handle(ReportPmExportWorker $worker): void
    {
        $worker->generate($this->reportExportId);
    }
}
