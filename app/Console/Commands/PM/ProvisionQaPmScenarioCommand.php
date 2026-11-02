<?php

namespace App\Console\Commands\PM;

use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmChecksheetPart;
use App\Models\PmChecksheetStandard;
use App\Models\PmExecution;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\User;
use App\Providers\TestingDatabaseServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProvisionQaPmScenarioCommand extends Command
{
    private const DATABASE = TestingDatabaseServiceProvider::APPROVED_MYSQL_DATABASE;

    private const ADMIN_USERNAME = 'admin.prime';

    private const OPERATOR_USERNAME = 'operator.prime';

    private const SEEDED_MACHINE_CODE = 'MCH-001';

    private const LOCATION_CODE = 'LOC-QA-PM';

    private const MACHINE_CODE = 'QA-PM-001';

    private const CHECKSHEET_CODE = 'QA-PM-001';

    private const PART_NAME = 'QA PM Part';

    private const STANDARD_NAME = 'QA PM Action';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'qa:provision-pm-scenario';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision the isolated QA PM browser scenario.';

    public function handle(): int
    {
        try {
            $this->assertSafeDatabase();

            DB::transaction(function (): void {
                $admin = $this->seededUser(self::ADMIN_USERNAME, 'admin');
                $this->seededUser(self::OPERATOR_USERNAME, 'operator');

                $machine = $this->usableSeededMachine() ?? $this->qaOwnedMachine();
                $checksheet = $this->checksheet($admin);
                $assignment = $this->assignment($checksheet, $machine, $admin);
                $part = $this->part($assignment);
                $this->standard($part);
                $schedule = $this->schedule($assignment, $admin);
                $this->occurrence($schedule, $machine);
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('QA PM browser scenario is ready.');

        return self::SUCCESS;
    }

    private function assertSafeDatabase(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('QA PM provisioning is allowed only in local or testing environments.');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('QA PM provisioning requires the isolated MySQL test database.');
        }

        $database = (string) ($connection->selectOne('SELECT DATABASE() AS database_name')->database_name ?? '');

        if ($database !== self::DATABASE) {
            throw new RuntimeException('QA PM provisioning requires the isolated MySQL test database.');
        }
    }

    private function seededUser(string $username, string $role): User
    {
        $user = User::withTrashed()->where('username', $username)->first();

        if (! $user || $user->trashed() || ! $user->is_active || $user->role !== $role) {
            throw new RuntimeException(sprintf('Required seeded %s account is not ready.', $role));
        }

        return $user;
    }

    private function usableSeededMachine(): ?Machine
    {
        $machine = Machine::query()
            ->with('location')
            ->where('machine_code', self::SEEDED_MACHINE_CODE)
            ->first();

        if ($machine
            && $machine->is_active
            && $machine->lifecycle_status === 'active'
            && $machine->location?->is_active) {
            return $machine;
        }

        return null;
    }

    private function qaOwnedMachine(): Machine
    {
        $location = Location::withTrashed()->where('location_code', self::LOCATION_CODE)->first();

        if ($location?->trashed()) {
            throw new RuntimeException('QA PM location is soft-deleted.');
        }

        if (! $location) {
            $location = Location::query()->create([
                'location_code' => self::LOCATION_CODE,
                'location_name' => 'QA PM Location',
                'is_active' => true,
            ]);
        }

        if (! $location->is_active) {
            throw new RuntimeException('QA PM location is inactive.');
        }

        $machine = Machine::withTrashed()->where('machine_code', self::MACHINE_CODE)->first();

        if ($machine?->trashed()) {
            throw new RuntimeException('QA PM machine is soft-deleted.');
        }

        if (! $machine) {
            $machine = Machine::query()->create([
                'location_id' => $location->id,
                'machine_code' => self::MACHINE_CODE,
                'machine_name' => 'QA PM Machine',
                'qr_token' => 'qa-pm-machine',
                'is_active' => true,
                'lifecycle_status' => 'active',
            ]);
        }

        if ((int) $machine->location_id !== (int) $location->id
            || ! $machine->is_active
            || $machine->lifecycle_status !== 'active') {
            throw new RuntimeException('QA PM machine is not browser-ready.');
        }

        return $machine;
    }

    private function checksheet(User $admin): PmChecksheet
    {
        $checksheet = PmChecksheet::withTrashed()->where('checksheet_code', self::CHECKSHEET_CODE)->first();

        if ($checksheet?->trashed()) {
            throw new RuntimeException('QA PM checksheet is soft-deleted.');
        }

        if (! $checksheet) {
            $checksheet = PmChecksheet::query()->create([
                'checksheet_code' => self::CHECKSHEET_CODE,
                'checksheet_name' => 'QA PM Browser Checksheet',
                'is_active' => true,
                'created_by' => $admin->id,
            ]);
        }

        if (! $checksheet->is_active) {
            throw new RuntimeException('QA PM checksheet is inactive.');
        }

        return $checksheet;
    }

    private function assignment(PmChecksheet $checksheet, Machine $machine, User $admin): PmChecksheetMachine
    {
        return PmChecksheetMachine::query()->firstOrCreate(
            [
                'pm_checksheet_id' => $checksheet->id,
                'machine_id' => $machine->id,
            ],
            [
                'assigned_at' => now(),
                'created_by' => $admin->id,
            ],
        );
    }

    private function part(PmChecksheetMachine $assignment): PmChecksheetPart
    {
        $part = PmChecksheetPart::query()->firstOrCreate(
            [
                'pm_checksheet_machine_id' => $assignment->id,
                'part_name' => self::PART_NAME,
            ],
            [
                'is_active' => true,
            ],
        );

        if (! $part->is_active) {
            throw new RuntimeException('QA PM part is inactive.');
        }

        return $part;
    }

    private function standard(PmChecksheetPart $part): void
    {
        $standard = PmChecksheetStandard::query()->firstOrCreate(
            [
                'pm_checksheet_part_id' => $part->id,
                'standard_name' => self::STANDARD_NAME,
            ],
            [
                'input_type' => 'action',
                'action_options' => ['OK', 'NEEDS_ATTENTION'],
                'is_required' => true,
                'is_active' => true,
            ],
        );

        if ($standard->input_type !== 'action'
            || ! $standard->is_active
            || ! $standard->is_required
            || ! in_array('OK', $standard->action_options ?? [], true)) {
            throw new RuntimeException('QA PM standard is not executable.');
        }
    }

    private function schedule(PmChecksheetMachine $assignment, User $admin): PmSchedule
    {
        $schedules = PmSchedule::withTrashed()
            ->where('pm_checksheet_machine_id', $assignment->id)
            ->get();

        if ($schedules->count() > 1) {
            throw new RuntimeException('QA PM assignment has multiple schedule eras.');
        }

        $schedule = $schedules->first();

        if ($schedule?->trashed()) {
            throw new RuntimeException('QA PM schedule is soft-deleted.');
        }

        if (! $schedule) {
            $schedule = PmSchedule::query()->create([
                'pm_checksheet_machine_id' => $assignment->id,
                'frequency_type' => 'daily',
                'operational_from' => now()->toDateString(),
                'start_date' => now()->toDateString(),
                'generate_until' => now()->addMonth()->toDateString(),
                'is_active' => true,
                'lifecycle_status' => 'active',
                'created_by' => $admin->id,
            ]);
        }

        if (! $schedule->is_active || $schedule->lifecycle_status !== 'active') {
            throw new RuntimeException('QA PM schedule is not live.');
        }

        return $schedule;
    }

    private function occurrence(PmSchedule $schedule, Machine $machine): void
    {
        $occurrence = PmScheduleDate::withTrashed()
            ->where('pm_schedule_id', $schedule->id)
            ->whereDate('scheduled_date', now()->toDateString())
            ->first();

        if ($occurrence?->trashed()) {
            throw new RuntimeException('QA PM occurrence is soft-deleted.');
        }

        if (! $occurrence) {
            $occurrence = PmScheduleDate::query()->create([
                'pm_schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'scheduled_date' => now()->toDateString(),
                'status' => 'scheduled',
            ]);
        }

        if ((int) $occurrence->machine_id !== (int) $machine->id
            || ! in_array($occurrence->status, ['scheduled', 'overdue'], true)) {
            throw new RuntimeException('QA PM occurrence is not Operator-startable.');
        }

        if (PmExecution::withTrashed()->where('pm_schedule_date_id', $occurrence->id)->exists()) {
            throw new RuntimeException('QA PM occurrence already has canonical execution history.');
        }
    }
}
