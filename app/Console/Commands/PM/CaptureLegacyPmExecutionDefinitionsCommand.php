<?php

namespace App\Console\Commands\PM;

use App\Models\PmExecution;
use App\Services\PM\PmExecutionService;
use Illuminate\Console\Command;

class CaptureLegacyPmExecutionDefinitionsCommand extends Command
{
    protected $signature = 'pm:capture-legacy-execution-definitions
        {--execution-id=* : Capture specific execution IDs}
        {--apply : Persist the capture; default is a dry run}';

    protected $description = 'Preview or capture definitions for legacy in-progress PM executions';

    public function __construct(
        private readonly PmExecutionService $executionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $executionIds = $this->optionIds();
        if ($executionIds === null) {
            $this->error('Semua --execution-id harus berupa integer positif.');

            return self::INVALID;
        }

        $query = PmExecution::withTrashed()
            ->whereNull('definition_snapshot_at')
            ->where('status', 'in_progress')
            ->orderBy('id');

        if ($executionIds !== []) {
            $query->whereIn('id', $executionIds);
        }

        $report = [
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'examined' => 0,
            'applied' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'details' => [],
        ];

        foreach ($query->pluck('id') as $executionId) {
            $result = $this->executionService->captureLegacyDefinitions((int) $executionId, (bool) $this->option('apply'));
            $report['examined']++;
            $report['applied'] += $result['applied'] ? 1 : 0;
            $report['skipped'] += $result['skipped'] ? 1 : 0;
            $report['conflicts'] += count($result['conflicts']);
            $report['details'][] = ['execution_id' => (int) $executionId, ...$result];
        }

        $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));

        return $report['conflicts'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<int>|null */
    private function optionIds(): ?array
    {
        $values = (array) $this->option('execution-id');
        $ids = [];

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
                return null;
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
