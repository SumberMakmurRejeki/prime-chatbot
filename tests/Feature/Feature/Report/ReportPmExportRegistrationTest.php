<?php

namespace Tests\Feature\Feature\Report;

use App\Models\Location;
use App\Models\Machine;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Report\ReportPmExportRegistrationService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ReportPmExportRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_admin_registration_persists_resolved_month_and_backlog_intent_without_dispatching(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake();

        $export = app(ReportPmExportRegistrationService::class)->register($admin, 'pdf', [
            'status' => 'approved',
            'search' => 'pump',
            'untrusted' => 'must not persist',
        ]);

        $this->assertInstanceOf(ReportExport::class, $export);
        $this->assertSame('pm', $export->report_type);
        $this->assertSame('pdf', $export->file_type);
        $this->assertSame('processing', $export->status);
        $this->assertSame($admin->id, $export->requested_by);
        $this->assertSame($admin->name, $export->requested_by_name_snapshot);
        $this->assertSame('approved', $export->filter_data['status']);
        $this->assertSame('pump', $export->filter_data['search']);
        $this->assertArrayNotHasKey('untrusted', $export->filter_data);
        $this->assertSame([
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
            'scope',
        ], array_keys($export->filter_data));
        $this->assertSame([
            'period_mode' => 'default_month',
            'resolved_start_date' => '2026-10-01',
            'resolved_end_date' => '2026-10-31',
            'include_attention_backlog' => true,
        ], $export->filter_data['scope']);
        Queue::assertNothingPushed();

        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-10-01', $export->fresh()->filter_data['scope']['resolved_start_date']);
        $this->assertSame('2026-10-31', $export->fresh()->filter_data['scope']['resolved_end_date']);
    }

    public function test_explicit_period_and_supported_filters_are_preserved_after_time_moves(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin']);
        $location = Location::query()->create([
            'location_code' => 'LOC-EXPORT-INTENT',
            'location_name' => 'Export Intent Location',
            'is_active' => true,
        ]);
        $machine = Machine::query()->create([
            'location_id' => $location->id,
            'machine_code' => 'MC-EXPORT-INTENT',
            'machine_name' => 'Export Intent Machine',
            'qr_token' => 'qr-export-intent',
            'is_active' => true,
        ]);
        $filters = [
            'start_date' => '2025-03-05',
            'end_date' => '2025-03-17',
            'location_id' => $location->id,
            'machine_id' => $machine->id,
            'part_name' => 'Bearing',
            'pic_operator' => 'Operator',
            'pic_admin' => 'Reviewer',
            'status' => 'waiting_review',
            'warning' => 'warning',
            'search' => 'pump',
            'export_type' => 'excel',
        ];

        $export = app(ReportPmExportRegistrationService::class)->register($admin, 'excel', $filters);
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Jakarta'));
        $persisted = $export->fresh()->filter_data;

        foreach (array_slice($filters, 0, 10, true) as $key => $value) {
            $this->assertSame($value, $persisted[$key]);
        }
        $this->assertSame([
            'period_mode' => 'explicit',
            'resolved_start_date' => '2025-03-05',
            'resolved_end_date' => '2025-03-17',
            'include_attention_backlog' => false,
        ], $persisted['scope']);
        $this->assertArrayNotHasKey('export_type', $persisted);
    }

    public function test_single_explicit_date_keeps_an_open_period_without_default_backlog(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $export = app(ReportPmExportRegistrationService::class)->register($admin, 'excel', [
            'end_date' => '2025-03-17',
        ]);

        $this->assertSame([
            'period_mode' => 'explicit',
            'resolved_start_date' => null,
            'resolved_end_date' => '2025-03-17',
            'include_attention_backlog' => false,
        ], $export->filter_data['scope']);
    }

    public function test_unauthorized_or_invalid_registration_does_not_create_an_export(): void
    {
        $service = app(ReportPmExportRegistrationService::class);
        $operator = User::factory()->create(['role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);

        try {
            $service->register($operator, 'pdf', []);
            $this->fail('Non-admin registration must be rejected.');
        } catch (AuthorizationException) {
        }

        try {
            $service->register($admin, 'csv', []);
            $this->fail('Unsupported export formats must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_registration_does_not_read_pm_rows_or_generate_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Storage::fake('public');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $export = app(ReportPmExportRegistrationService::class)->register($admin, 'pdf', []);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $pmReads = collect($queries)->filter(
            fn (array $query): bool => preg_match('/\\b(pm_schedule_dates|pm_executions|pm_execution_items|pm_execution_item_action_results)\\b/i', $query['query']) === 1,
        );

        $this->assertCount(0, $pmReads);
        $this->assertSame('processing', $export->status);
        $this->assertNull($export->file_name);
        $this->assertNull($export->file_path);
        $this->assertNull($export->completed_at);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
