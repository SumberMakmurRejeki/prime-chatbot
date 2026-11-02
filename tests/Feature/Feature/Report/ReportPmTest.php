<?php

namespace Tests\Feature\Feature\Report;

use App\Exports\PM\ReportPmExport;
use App\Models\GuestSession;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PmChecksheet;
use App\Models\PmChecksheetMachine;
use App\Models\PmExecution;
use App\Models\PmExecutionItem;
use App\Models\PmSchedule;
use App\Models\PmScheduleDate;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Report\ReportExportService;
use App\Services\Report\ReportPmService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportPmTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected Machine $machineA;

    protected Machine $machineB;

    public function test_pic_admin_filter_has_independent_apply_action_and_scopes_report_data(): void
    {
        $approvedExecution = PmExecution::query()
            ->where('machine_id', $this->machineA->id)
            ->where('status', 'approved')
            ->firstOrFail();

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'pic_admin' => $this->admin->name,
        ]));

        $response->assertOk();

        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $xpath = new \DOMXPath($document);
        $filterForm = $xpath->query('//form[@data-report-pm-filter-form]')->item(0);
        $this->assertNotNull($filterForm);

        $applyButton = $xpath->query('.//button[@type="submit" and @data-report-pm-filter-apply]', $filterForm)->item(0);
        $this->assertNotNull($applyButton);
        $this->assertSame('Terapkan Filter', trim($applyButton->textContent));

        $picAdminInput = $xpath->query('.//input[@name="pic_admin"]', $filterForm)->item(0);
        $this->assertNotNull($picAdminInput);
        $this->assertSame($this->admin->name, $picAdminInput->getAttribute('value'));
        $this->assertSame(1, $xpath->query('.//details[@open]', $filterForm)->length);

        $this->assertSame($this->admin->name, $response['filters']['pic_admin']);
        $this->assertSame([$approvedExecution->pm_schedule_date_id], $response['rows']->pluck('schedule_date_id')->all());
        $this->assertRenderedSummary($response, [
            'Total PM' => 1,
            'Scheduled' => 0,
            'Overdue' => 0,
            'Missed' => 0,
            'In Progress' => 0,
            'Waiting Review' => 0,
            'Approved' => 1,
            'Warning' => 0,
        ]);
    }

    public function test_report_pm_screen_exposes_parent_job_summary_metrics(): void
    {
        $fixture = $this->createSummaryFixture();

        $response = $this->actingAs($this->admin)->get('/report/pm');

        $response->assertOk();
        $this->assertSame(6, $response['rows']->total());
        $this->assertNotContains($fixture['older_approved']->pm_schedule_date_id, $response['rows']->pluck('schedule_date_id')->all());
        $waitingReview = $response['rows']->firstWhere('schedule_date_id', $fixture['waiting_review']->pm_schedule_date_id);
        $this->assertSame('overdue', $waitingReview->schedule_status);
        $this->assertSame('waiting_review', $waitingReview->execution_status);
        $this->assertSame(1, (int) $waitingReview->is_warning);
        $this->assertRenderedSummary($response, [
            'Total PM' => 6,
            'Scheduled' => 1,
            'Overdue' => 1,
            'Missed' => 1,
            'In Progress' => 1,
            'Waiting Review' => 1,
            'Approved' => 1,
            'Warning' => 1,
        ]);
    }

    public function test_report_pm_summary_and_table_share_waiting_review_filter(): void
    {
        $fixture = $this->createSummaryFixture();

        $response = $this->actingAs($this->admin)->get('/report/pm?status=waiting_review');

        $response->assertOk();
        $this->assertSame(1, $response['rows']->total());
        $this->assertSame([$fixture['waiting_review']->pm_schedule_date_id], $response['rows']->pluck('schedule_date_id')->all());
        $this->assertRenderedSummary($response, [
            'Total PM' => 1,
            'Scheduled' => 0,
            'Overdue' => 0,
            'Missed' => 0,
            'In Progress' => 0,
            'Waiting Review' => 1,
            'Approved' => 0,
            'Warning' => 1,
        ]);
    }

    public function test_report_pm_summary_and_table_share_explicit_historical_period(): void
    {
        $fixture = $this->createSummaryFixture();

        $response = $this->actingAs($this->admin)->get('/report/pm?start_date=2026-09-25&end_date=2026-09-25');

        $response->assertOk();
        $this->assertSame(1, $response['rows']->total());
        $this->assertSame([$fixture['older_approved']->pm_schedule_date_id], $response['rows']->pluck('schedule_date_id')->all());
        $this->assertRenderedSummary($response, [
            'Total PM' => 1,
            'Scheduled' => 0,
            'Overdue' => 0,
            'Missed' => 0,
            'In Progress' => 0,
            'Waiting Review' => 0,
            'Approved' => 1,
            'Warning' => 0,
        ]);
    }

    public function test_report_pm_parent_row_exposes_expandable_frozen_execution_results(): void
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineB->id)->firstOrFail();
        $number = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Bearing',
            'standard_name_snapshot' => 'Frozen Temperature',
            'input_type_snapshot' => 'number',
            'target_value_snapshot' => 40,
            'unit_snapshot' => 'C',
            'number_value' => 42,
            'is_warning' => true,
            'warning_message' => 'Outside target',
        ]);
        $range = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Bearing',
            'standard_name_snapshot' => 'Frozen Pressure',
            'input_type_snapshot' => 'range',
            'number_value' => 70,
            'min_value_snapshot' => 10,
            'max_value_snapshot' => 80,
            'unit_snapshot' => 'kPa',
            'is_warning' => false,
        ]);
        $singleAction = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Bearing',
            'standard_name_snapshot' => 'Frozen Inspection',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'single',
            'action_options_snapshot' => ['Pass', 'Fail'],
            'action_value' => 'Pass',
            'is_warning' => false,
        ]);
        $multipleAction = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Bearing',
            'standard_name_snapshot' => 'Frozen Lubrication',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'multiple',
            'action_options_snapshot' => ['Inspect', 'Lubricate', 'Clean'],
            'is_warning' => false,
        ]);
        $secondPart = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Gearbox',
            'standard_name_snapshot' => 'Frozen Oil Level',
            'input_type_snapshot' => 'number',
            'number_value' => 55,
            'unit_snapshot' => 'mm',
            'is_warning' => false,
        ]);
        foreach (['Lubricate', 'Clean'] as $position => $label) {
            $multipleAction->actionResults()->create([
                'selected_label_snapshot' => $label,
                'selected_label_identity_hash' => hash('sha256', $label),
                'option_position_snapshot' => $position + 1,
            ]);
        }

        $today = now()->toDateString();
        $baseFilters = [
            'machine_id' => $this->machineB->id,
            'start_date' => $today,
            'end_date' => $today,
        ];
        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query($baseFilters));

        $response->assertOk();
        $this->assertSame(1, $response['rows']->total());
        $this->assertSame(2, substr_count($response->getContent(), 'Frozen Lubrication'));
        foreach (['Frozen Bearing', 'Frozen Gearbox', 'Frozen Temperature', 'Frozen Pressure', 'Frozen Inspection', 'Frozen Oil Level', '42', '70', '55', 'Pass', 'Lubricate', 'Clean', 'Outside target', 'Detail Report PM'] as $expected) {
            $response->assertSee($expected);
        }
        $response->assertSeeTextInOrder([
            'Frozen Temperature',
            '42.00 C',
            'Target: 40.00 C',
            'Frozen Pressure',
            '70.00 kPa',
            'Rentang: 10.00–80.00 kPa',
        ]);
        $this->assertTrue($number->is_warning);
        $this->assertTrue($range->exists);
        $this->assertTrue($singleAction->exists);
        $this->assertTrue($secondPart->exists);
        $response->assertSee(route('pm-review.show', $execution->id));

        $scheduleOnly = PmScheduleDate::query()->where('machine_id', $this->machineB->id)->where('status', 'scheduled')->firstOrFail();
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $scheduleOnly->id)->count());
        $scheduleOnlyResponse = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'machine_id' => $this->machineB->id,
            'start_date' => Carbon::parse($scheduleOnly->scheduled_date)->toDateString(),
            'end_date' => Carbon::parse($scheduleOnly->scheduled_date)->toDateString(),
        ]));
        $scheduleOnlyResponse->assertOk()->assertSee('Belum ada pelaksanaan PM')->assertDontSee('data-report-pm-hierarchy');

        $partFocused = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query(array_merge($baseFilters, [
            'part_name' => 'Frozen Bearing',
        ])));
        $partFocused->assertOk()->assertSee('Frozen Temperature')->assertDontSee('Frozen Oil Level');
        $this->assertSame(2, substr_count($partFocused->getContent(), 'Frozen Lubrication'));

        $searchFocused = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query(array_merge($baseFilters, [
            'search' => 'Frozen Oil',
        ])));
        $searchFocused->assertOk()->assertSee('Frozen Oil Level')->assertDontSee('Frozen Temperature');

        $parentOnlySearch = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query(array_merge($baseFilters, [
            'search' => 'M-B-01',
        ])));
        $parentOnlySearch->assertOk()->assertSee('Frozen Temperature')->assertSee('Frozen Oil Level');
        $parentOnlySearch->assertSee(route('pm-review.show', $execution->id));
    }

    public function test_report_pm_batch_loads_page_items_and_action_results(): void
    {
        foreach (PmExecution::query()->get() as $execution) {
            $label = 'Inspect '.$execution->machine_code_snapshot;
            $item = $execution->items()->create([
                'part_name_snapshot' => 'Batch Action Part',
                'standard_name_snapshot' => 'Batch Action Standard',
                'input_type_snapshot' => 'action',
                'selection_mode_snapshot' => 'multiple',
                'action_options_snapshot' => [$label],
                'is_warning' => false,
            ]);
            $item->actionResults()->create([
                'selected_label_snapshot' => $label,
                'selected_label_identity_hash' => hash('sha256', $label),
                'option_position_snapshot' => 0,
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower(str_replace(['"', '`'], '', $query->sql)),
                'bindings' => $query->bindings,
            ];
        });

        $response = $this->actingAs($this->admin)->get('/report/pm');

        $response->assertOk()->assertSee('Inspect M-A-01')->assertSee('Inspect M-B-01');
        $executionRows = $response['rows']->getCollection()->filter(fn (object $row): bool => $row->execution_id !== null);
        $this->assertCount(2, $executionRows);
        foreach ($executionRows as $row) {
            $this->assertSame(2, $row->execution_child_parts->sum(fn (array $part): int => $part['items']->count()));
        }

        $batchedItemQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'from pm_execution_items')
            && preg_match('/where pm_execution_id in\s*\(/', $query['sql']) === 1));
        $perParentItemQueries = array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'from pm_execution_items')
            && preg_match('/where pm_execution_id\s*=\s*\?/', $query['sql']) === 1);
        $actionResultQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'from pm_execution_item_action_results')));

        $this->assertCount(1, $batchedItemQueries);
        $this->assertSame(
            $executionRows->pluck('execution_id')->map(fn (int|string $id): int => (int) $id)->sort()->values()->all(),
            collect($batchedItemQueries[0]['bindings'])->map(fn (int|string $id): int => (int) $id)->sort()->values()->all(),
        );
        $this->assertCount(0, $perParentItemQueries);
        $this->assertCount(1, $actionResultQueries);
        $this->assertMatchesRegularExpression('/pm_execution_item_id in\s*\(/', $actionResultQueries[0]['sql']);
    }

    public function test_report_pm_preserves_part_focus_when_search_matches_parent(): void
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineB->id)->firstOrFail();
        $checksheetMachine = PmChecksheetMachine::query()->where('machine_id', $this->machineB->id)->firstOrFail();
        $parts = [];
        foreach ([
            ['Frozen Bearing', 'Frozen Temperature'],
            ['Frozen Bearing', 'Frozen Condition'],
            ['Frozen Gearbox', 'Frozen Oil Level'],
            ['Frozen Gearbox', 'Frozen Leakage'],
        ] as [$partName, $standardName]) {
            $parts[$partName] ??= $checksheetMachine->parts()->create([
                'part_name' => 'Current '.$partName,
                'is_active' => true,
            ]);
            $execution->items()->create([
                'pm_checksheet_part_id' => $parts[$partName]->id,
                'part_name_snapshot' => $partName,
                'standard_name_snapshot' => $standardName,
                'input_type_snapshot' => 'number',
                'number_value' => 55,
                'is_warning' => false,
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'part_name' => 'Frozen Bearing',
            'search' => 'M-B-01',
        ]));

        $response->assertOk();
        $this->assertSame([$execution->id], $response['rows']->pluck('execution_id')->all());
        $this->assertSame(['Frozen Bearing'], $response['rows']->first()->execution_child_parts->pluck('name')->all());
        $response->assertSee('Frozen Temperature')->assertSee('Frozen Condition');
        $response->assertDontSee('Frozen Oil Level')->assertDontSee('Frozen Leakage');
        $response->assertSee(route('pm-review.show', $execution->id));

        $this->get(route('pm-review.show', $execution->id))
            ->assertOk()
            ->assertSee('Frozen Bearing')
            ->assertSee('Frozen Temperature')
            ->assertSee('Frozen Condition')
            ->assertSee('Frozen Gearbox')
            ->assertSee('Frozen Oil Level')
            ->assertSee('Frozen Leakage');

        $childSearch = $this->get('/report/pm?'.http_build_query([
            'part_name' => 'Frozen Bearing',
            'search' => 'Frozen Temperature',
        ]));
        $childSearch->assertOk()->assertSee('Frozen Temperature')->assertDontSee('Frozen Condition');
        $childSearch->assertDontSee('Frozen Oil Level')->assertDontSee('Frozen Leakage');
        $this->assertSame([$execution->id], $childSearch['rows']->pluck('execution_id')->all());
        $this->assertSame(['Frozen Bearing'], $childSearch['rows']->first()->execution_child_parts->pluck('name')->all());

        $otherPartSearch = $this->get('/report/pm?'.http_build_query([
            'part_name' => 'Frozen Bearing',
            'search' => 'Frozen Oil Level',
        ]));
        $otherPartSearch->assertOk()->assertSee('Frozen Temperature')->assertSee('Frozen Condition');
        $otherPartSearch->assertDontSeeText('Frozen Oil Level')->assertDontSeeText('Frozen Leakage');
        $this->assertSame([$execution->id], $otherPartSearch['rows']->pluck('execution_id')->all());
        $this->assertSame(['Frozen Bearing'], $otherPartSearch['rows']->first()->execution_child_parts->pluck('name')->all());
    }

    public function test_report_pm_screen_exposes_machine_and_advanced_filters(): void
    {
        $response = $this->actingAs($this->admin)->get('/report/pm');

        $response->assertOk();
        $response->assertSee('name="machine_id"', escape: false);
        $response->assertSee('Advanced Filters');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'Asia/Jakarta'));

        $locationA = Location::query()->create([
            'location_code' => 'LOC-A',
            'location_name' => 'Line Produksi A',
            'is_active' => true,
        ]);

        $locationB = Location::query()->create([
            'location_code' => 'LOC-B',
            'location_name' => 'Line Produksi B',
            'is_active' => true,
        ]);

        $this->machineA = Machine::query()->create([
            'location_id' => $locationA->id,
            'machine_code' => 'M-A-01',
            'machine_name' => 'Mesin A',
            'qr_token' => 'qr-a-01',
            'is_active' => true,
        ]);

        $this->machineB = Machine::query()->create([
            'location_id' => $locationB->id,
            'machine_code' => 'M-B-01',
            'machine_name' => 'Mesin B',
            'qr_token' => 'qr-b-01',
            'is_active' => true,
        ]);

        $this->admin = User::query()->create([
            'name' => 'Admin PRIME',
            'username' => 'admin.prime',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->operator = User::query()->create([
            'name' => 'Operator PRIME',
            'username' => 'operator.prime',
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->createExecutionWithItem(
            machine: $this->machineA,
            scheduledDate: now()->subDay()->toDateString(),
            submittedAt: now()->subDay()->setTime(10, 30, 0),
            status: 'approved',
            scheduleStatus: 'approved',
            isWarning: false,
            partName: 'Part A',
            note: 'Catatan operator A',
        );

        $this->createExecutionWithItem(
            machine: $this->machineB,
            scheduledDate: now()->toDateString(),
            submittedAt: now()->setTime(10, 30, 0),
            status: 'waiting_review',
            scheduleStatus: 'waiting_review',
            isWarning: true,
            partName: 'Part B',
            note: 'Perlu dicek ulang di shift malam',
        );

        $this->createScheduleOnly(
            machine: $this->machineB,
            scheduledDate: now()->addDay()->toDateString(),
            status: 'scheduled',
        );
    }

    /**
     * Slice B: Review dan Report harus memperlakukan bundle snapshot secara atomik.
     * Satu nilai yang hilang atau kosong tidak boleh menghasilkan identitas parsial.
     */
    public function test_review_and_report_require_four_usable_snapshot_values(): void
    {
        $service = app(ReportPmService::class);
        $executionId = (int) $service->exportRows([])->firstWhere('execution_id', '!=', null)->execution_id;
        $legacyLabel = 'Data historis tidak tersedia (legacy)';
        $completeSnapshot = [
            'machine_code_snapshot' => 'M-A-HIST',
            'machine_name_snapshot' => 'Mesin A Historis',
            'location_code_snapshot' => 'LOC-A-HIST',
            'location_name_snapshot' => 'Line Historis',
        ];
        $cases = [
            'missing_machine_code' => ['machine_code_snapshot' => null],
            'missing_machine_name' => ['machine_name_snapshot' => null],
            'missing_location_code' => ['location_code_snapshot' => null],
            'missing_location_name' => ['location_name_snapshot' => null],
            'two_of_four_usable' => ['machine_name_snapshot' => null, 'location_name_snapshot' => null],
            'one_of_four_usable' => ['machine_name_snapshot' => null, 'location_code_snapshot' => null, 'location_name_snapshot' => null],
            'zero_of_four_usable' => array_fill_keys(array_keys($completeSnapshot), null),
            'empty_string' => ['machine_code_snapshot' => ''],
            'whitespace_only' => ['machine_code_snapshot' => '   '],
        ];

        foreach ($cases as $case => $overrides) {
            PmExecution::query()->whereKey($executionId)->update(array_merge($completeSnapshot, $overrides));

            $this->actingAs($this->admin)
                ->get('/pm/review')
                ->assertOk()
                ->assertSee($legacyLabel);

            $row = $service->exportRows([])->firstWhere('execution_id', $executionId);
            $this->assertNotNull($row, $case);
            $this->assertSame($legacyLabel, $row->machine_code, $case);
            $this->assertSame($legacyLabel, $row->machine_name, $case);
            $this->assertSame($legacyLabel, $row->location_name, $case);

            $this->actingAs($this->admin)
                ->get('/report/pm')
                ->assertOk()
                ->assertSee($legacyLabel);
        }
    }

    /**
     * Slice B: identitas Report tetap memakai snapshot ketika Location master dihapus soft delete.
     */
    public function test_report_pm_snapshot_survives_current_location_soft_delete_for_all_output_surfaces(): void
    {
        $service = app(ReportPmService::class);
        $rowBefore = $service->exportRows([])->firstWhere('machine_code', 'M-A-01');
        $this->assertNotNull($rowBefore);

        $location = $this->machineA->location;
        $this->machineA->forceFill(['machine_code' => 'M-A-LIVE', 'machine_name' => 'Mesin A Live'])->save();
        $location->forceFill(['location_name' => 'Line Live'])->save();
        $location->delete();

        $row = $service->exportRows([])->firstWhere('execution_id', $rowBefore->execution_id);
        $this->assertNotNull($row);
        $this->assertSame('M-A-01', $row->machine_code);
        $this->assertSame('Mesin A', $row->machine_name);
        $this->assertSame('Line Produksi A', $row->location_name);

        $this->actingAs($this->admin)
            ->get('/report/pm')
            ->assertOk()
            ->assertSee('<td class="text-left font-semibold">M-A-01</td>', escape: false)
            ->assertSee('<td class="text-left">Line Produksi A</td>', escape: false)
            ->assertDontSee('M-A-LIVE')
            ->assertDontSee('Line Live');

        $mapped = (new ReportPmExport(collect([$row]), $service))->map($row);
        $this->assertContains('M-A-01', $mapped);
        $this->assertContains('Mesin A', $mapped);
        $this->assertContains('Line Produksi A', $mapped);
        $this->assertNotContains('M-A-LIVE', $mapped);
        $this->assertNotContains('Line Live', $mapped);

        $view = $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups(collect([$row])),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => ['total' => 1],
            'printedBy' => $this->admin->name,
        ]);
        $view->assertSee('M-A-01')
            ->assertSee('Mesin A')
            ->assertSee('Line Produksi A')
            ->assertDontSee('M-A-LIVE')
            ->assertDontSee('Line Live');
    }

    public function test_report_pm_export_rows_use_transaction_snapshots_after_master_changes(): void
    {
        $rowBefore = app(ReportPmService::class)->exportRows([])->firstWhere('machine_code', 'M-A-01');
        $this->assertNotNull($rowBefore);

        $location = $this->machineA->location;
        $this->machineA->forceFill(['machine_code' => 'M-A-LIVE', 'machine_name' => 'Mesin A Live'])->save();
        $location->forceFill(['location_name' => 'Line Live'])->save();

        $row = app(ReportPmService::class)->exportRows([])->firstWhere('execution_id', $rowBefore->execution_id);
        $this->assertNotNull($row);
        $this->assertSame('M-A-01', $row->machine_code);
        $this->assertSame('Mesin A', $row->machine_name);
        $this->assertSame('Line Produksi A', $row->location_name);
    }

    public function test_report_pm_item_filters_and_export_use_item_snapshots_only(): void
    {
        // Master part/standard berubah setelah hasil masuk; filter dan export
        // tetap berbasis pm_execution_items *_snapshot, bukan master terkini.
        $row = app(ReportPmService::class)->exportRows(['part_name' => 'Part A'])->firstWhere('machine_code', 'M-A-01');
        $this->assertNotNull($row);
        $this->assertSame('Part A', $row->part_name_snapshot);
        $this->assertSame('Standard Part A', $row->standard_name_snapshot);

        $filtered = app(ReportPmService::class)->exportRows(['search' => 'Standard Part A'])->pluck('execution_id')->all();
        $this->assertCount(1, $filtered);
    }

    public function test_report_pm_incomplete_execution_snapshot_is_explicitly_legacy_unavailable(): void
    {
        $executionId = (int) app(ReportPmService::class)->exportRows([])->firstWhere('machine_code', 'M-A-01')->execution_id;
        PmExecution::query()->whereKey($executionId)->update([
            'machine_code_snapshot' => null,
            'machine_name_snapshot' => null,
            'location_code_snapshot' => null,
            'location_name_snapshot' => null,
        ]);

        $row = app(ReportPmService::class)->exportRows([])->firstWhere('execution_id', $executionId);
        $this->assertNotNull($row);
        $this->assertSame('Data historis tidak tersedia (legacy)', $row->machine_code);
        $this->assertSame('Data historis tidak tersedia (legacy)', $row->machine_name);
        $this->assertSame('Data historis tidak tersedia (legacy)', $row->location_name);
    }

    public function test_report_pm_html_uses_transaction_snapshots_after_master_changes(): void
    {
        $executionId = (int) app(ReportPmService::class)->exportRows([])->firstWhere('machine_code', 'M-A-01')->execution_id;

        $location = $this->machineA->location;
        $this->machineA->forceFill(['machine_code' => 'M-A-LIVE', 'machine_name' => 'Mesin A Live'])->save();
        $location->forceFill(['location_name' => 'Line Live'])->save();

        // Filter controls intentionally retain live master values for filtering.
        $this->actingAs($this->admin)
            ->get('/report/pm')
            ->assertOk()
            ->assertSee('M-A-01')
            ->assertSee('Mesin A')
            ->assertSee('Line Produksi A')
            ->assertDontSee('M-A-LIVE')
            ->assertDontSee('Mesin A Live');

        $this->assertNotNull($executionId);
    }

    public function test_report_pm_excel_map_uses_transaction_snapshots_after_master_changes(): void
    {
        $service = app(ReportPmService::class);
        $rowBefore = $service->exportRows([])->firstWhere('machine_code', 'M-A-01');

        $location = $this->machineA->location;
        $this->machineA->forceFill(['machine_code' => 'M-A-LIVE', 'machine_name' => 'Mesin A Live'])->save();
        $location->forceFill(['location_name' => 'Line Live'])->save();

        $row = $service->exportRows([])->firstWhere('execution_id', $rowBefore->execution_id);
        $this->assertNotNull($row);

        $mapped = (new ReportPmExport(collect([$row]), $service))->map($row);

        $this->assertContains('M-A-01', $mapped);
        $this->assertContains('Mesin A', $mapped);
        $this->assertContains('Line Produksi A', $mapped);
        $this->assertNotContains('M-A-LIVE', $mapped);
        $this->assertNotContains('Mesin A Live', $mapped);
        $this->assertNotContains('Line Live', $mapped);
    }

    public function test_report_pm_pdf_uses_transaction_snapshots_after_master_changes(): void
    {
        $service = app(ReportPmService::class);
        $rowBefore = $service->exportRows([])->firstWhere('machine_code', 'M-A-01');

        $location = $this->machineA->location;
        $this->machineA->forceFill(['machine_code' => 'M-A-LIVE', 'machine_name' => 'Mesin A Live'])->save();
        $location->forceFill(['location_name' => 'Line Live'])->save();

        $row = $service->exportRows([])->firstWhere('execution_id', $rowBefore->execution_id);
        $this->assertNotNull($row);

        $view = $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups(collect([$row])),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => ['total' => 1],
            'printedBy' => $this->admin->name,
        ]);

        $view->assertSee('M-A-01');
        $view->assertSee('Mesin A');
        $view->assertSee('Line Produksi A');
        $view->assertDontSee('M-A-LIVE');
        $view->assertDontSee('Mesin A Live');
        $view->assertDontSee('Line Live');
    }

    public function test_report_pm_legacy_snapshot_label_renders_in_html_excel_and_pdf(): void
    {
        $service = app(ReportPmService::class);
        $executionId = (int) $service->exportRows([])->firstWhere('machine_code', 'M-A-01')->execution_id;
        PmExecution::query()->whereKey($executionId)->update([
            'machine_code_snapshot' => null,
            'machine_name_snapshot' => null,
            'location_code_snapshot' => null,
            'location_name_snapshot' => null,
        ]);

        $row = $service->exportRows([])->firstWhere('execution_id', $executionId);
        $this->assertNotNull($row);

        $legacyLabel = 'Data historis tidak tersedia (legacy)';

        // Filter controls intentionally retain live master values for filtering.
        $this->actingAs($this->admin)
            ->get('/report/pm')
            ->assertOk()
            ->assertSee($legacyLabel)
            ->assertDontSee('Mesin A');

        // Excel mapping
        $mapped = (new ReportPmExport(collect([$row]), $service))->map($row);
        $this->assertContains($legacyLabel, $mapped);
        $this->assertNotContains('Mesin A', $mapped);
        $this->assertNotContains('Line Produksi A', $mapped);

        // PDF Blade
        $view = $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups(collect([$row])),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => ['total' => 1],
            'printedBy' => $this->admin->name,
        ]);
        $view->assertSee($legacyLabel);
        $view->assertDontSee('Mesin A');
        $view->assertDontSee('Line Produksi A');
    }

    public function test_report_pm_schedule_only_rows_keep_current_master_identity(): void
    {
        $row = app(ReportPmService::class)->exportRows([])->firstWhere('execution_id', null);

        $this->assertNotNull($row);
        $this->assertSame('M-B-01', $row->machine_code);
        $this->assertSame('Mesin B', $row->machine_name);
        $this->assertSame('Line Produksi B', $row->location_name);
    }

    public function test_admin_can_access_report_pm_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/report/pm');

        $response->assertOk();
        $response->assertSee('Report PM');
        $response->assertSee('M-A-01');
        $response->assertSee('M-B-01');
        $response->assertSee('Scheduled');
    }

    public function test_operator_and_guest_cannot_access_report_pm_page(): void
    {
        $this->actingAs($this->operator)
            ->get('/report/pm')
            ->assertRedirect('/403');

        $guestSession = GuestSession::query()->create([
            'guest_name' => 'Guest PRIME',
            'session_id' => 'guest-session-report',
            'login_at' => now(),
        ]);

        $this->withSession([
            'guest_session_id' => $guestSession->id,
            'guest_name' => $guestSession->guest_name,
        ])->get('/report/pm')
            ->assertRedirect('/403');
    }

    public function test_report_pm_filter_by_machine_and_warning(): void
    {
        $response = $this->actingAs($this->admin)->get('/report/pm?machine_id='.$this->machineB->id.'&warning=warning');

        $response->assertOk();
        $response->assertSee('M-B-01');
        $response->assertSee('Menampilkan 1-1 dari total 1 data');
    }

    public function test_report_pm_filter_by_schedule_date_status_and_pic(): void
    {
        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'waiting_review',
            'pic_operator' => $this->operator->name,
        ]));

        $response->assertOk();
        $response->assertSee('M-B-01');
        $response->assertSee('Menampilkan 1-1 dari total 1 data');
    }

    public function test_report_pm_filter_status_scheduled_returns_schedule_without_execution(): void
    {
        $scheduleOnly = PmScheduleDate::query()
            ->where('machine_id', $this->machineB->id)
            ->where('status', 'scheduled')
            ->firstOrFail();
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $scheduleOnly->id)->count());

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]));

        $response->assertSee('Belum ada pelaksanaan PM');
        $response->assertSee('Scheduled');
        $response->assertSee('M-B-01');
        $this->assertSame(0, PmExecution::query()->where('pm_schedule_date_id', $scheduleOnly->id)->count());
    }

    public function test_report_does_not_describe_soft_deleted_execution_as_never_started(): void
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineB->id)->firstOrFail();
        $scheduleDate = PmScheduleDate::query()->findOrFail($execution->pm_schedule_date_id);
        $execution->delete();

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'machine_id' => $this->machineB->id,
            'status' => 'waiting_review',
        ]));
        $row = $response['rows']->firstWhere('schedule_date_id', $scheduleDate->id);

        $this->assertNotNull($row);
        $this->assertNull($row->execution_id);
        $this->assertSame(1, (int) $row->has_deleted_execution);
        $response->assertSee('Data pelaksanaan tidak tersedia');
        $response->assertDontSee('Belum ada pelaksanaan PM');
    }

    public function test_report_filter_and_visible_status_prefer_canonical_execution_over_stale_occurrence(): void
    {
        $scheduleDate = PmScheduleDate::query()
            ->where('machine_id', $this->machineB->id)
            ->where('status', 'waiting_review')
            ->firstOrFail();
        // Snapshot occurrence legacy dapat tertinggal; Report tetap memakai status execution canonical.
        $scheduleDate->forceFill(['status' => 'overdue'])->save();

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'machine_id' => $this->machineB->id,
            'status' => 'waiting_review',
        ]));

        $response->assertSee('Waiting Review');
        $this->assertSame([$scheduleDate->id], $response['rows']->pluck('schedule_date_id')->all());
    }

    public function test_report_pm_pagination_limited_to_10_rows(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'Asia/Jakarta'));

        for ($i = 0; $i < 11; $i++) {
            $this->createExecutionWithItem(
                machine: $this->machineA,
                scheduledDate: now()->startOfMonth()->addDays($i)->toDateString(),
                submittedAt: now()->startOfMonth()->addDays($i)->setTime(8, 0, 0),
                status: 'approved',
                scheduleStatus: 'approved',
                isWarning: false,
                partName: 'Part Pagination '.$i,
            );
        }

        $response = $this->actingAs($this->admin)->get('/report/pm');

        $response->assertOk();
        $response->assertSee('Menampilkan 1-10');
    }

    public function test_multiple_execution_items_and_action_selections_count_as_one_report_parent(): void
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineB->id)->firstOrFail();

        $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Standard Suhu',
            'input_type_snapshot' => 'number',
            'number_value' => 45,
            'is_warning' => false,
        ]);
        $multipleAction = $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Standard Pemeriksaan',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'multiple',
            'action_options_snapshot' => ['OK', 'LUBRIKASI', 'BERSIHKAN'],
            'is_warning' => false,
        ]);
        $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Standard Rentang',
            'input_type_snapshot' => 'range',
            'number_value' => 50,
            'min_value_snapshot' => 0,
            'max_value_snapshot' => 100,
            'is_warning' => false,
        ]);
        $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Standard Pilihan Tunggal',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'single',
            'action_options_snapshot' => ['OK', 'NG'],
            'action_value' => 'OK',
            'is_warning' => false,
        ]);

        $snapshotFields = [
            'id',
            'input_type_snapshot',
            'selection_mode_snapshot',
            'action_options_snapshot',
            'action_value',
            'number_value',
            'min_value_snapshot',
            'max_value_snapshot',
        ];
        $snapshotsBeforeReport = $execution->items()
            ->orderBy('id')
            ->get()
            ->map(fn (PmExecutionItem $item): array => $item->only($snapshotFields))
            ->all();

        foreach (['OK', 'LUBRIKASI', 'BERSIHKAN'] as $position => $label) {
            $multipleAction->actionResults()->create([
                'selected_label_snapshot' => $label,
                'selected_label_identity_hash' => hash('sha256', $label),
                'option_position_snapshot' => $position + 1,
            ]);
        }
        $actionResultsBeforeReport = $multipleAction->actionResults()
            ->get(['id', 'selected_label_snapshot', 'option_position_snapshot'])
            ->map(fn ($result): array => $result->only(['id', 'selected_label_snapshot', 'option_position_snapshot']))
            ->all();

        $this->assertSame(5, $execution->items()->count());
        $this->assertSame(3, $multipleAction->actionResults()->count());

        $response = $this->actingAs($this->admin)->get('/report/pm?'.http_build_query([
            'machine_id' => $this->machineB->id,
            'part_name' => 'Part B',
            'search' => 'Standard Suhu',
            'status' => 'waiting_review',
        ]));

        $warningRows = $this->get('/report/pm?'.http_build_query([
            'machine_id' => $this->machineB->id,
            'status' => 'waiting_review',
            'warning' => 'warning',
        ]));
        $normalRows = $this->get('/report/pm?'.http_build_query([
            'machine_id' => $this->machineB->id,
            'status' => 'waiting_review',
            'warning' => 'normal',
        ]));

        $this->assertSame(1, $warningRows['rows']->total());
        $this->assertSame(0, $normalRows['rows']->total());

        $itemIds = $execution->items()->orderBy('id')->pluck('id')->all();
        $exportRows = app(ReportPmService::class)->exportRows([
            'machine_id' => $this->machineB->id,
            'status' => 'waiting_review',
        ]);

        $this->assertSame($itemIds, $exportRows->pluck('execution_item_id')->all());
        $snapshotsAfterReport = $execution->items()
            ->orderBy('id')
            ->get()
            ->map(fn (PmExecutionItem $item): array => $item->only($snapshotFields))
            ->all();
        $this->assertSame($snapshotsBeforeReport, $snapshotsAfterReport);
        $this->assertSame(
            ['OK', 'LUBRIKASI', 'BERSIHKAN'],
            $multipleAction->actionResults()->orderBy('option_position_snapshot')->pluck('selected_label_snapshot')->all(),
        );
        $actionResultsAfterReport = $multipleAction->actionResults()
            ->get(['id', 'selected_label_snapshot', 'option_position_snapshot'])
            ->map(fn ($result): array => $result->only(['id', 'selected_label_snapshot', 'option_position_snapshot']))
            ->all();
        $this->assertSame($actionResultsBeforeReport, $actionResultsAfterReport);
        $this->assertSame(1, (int) $response['rows']->first()->is_warning);
        $this->get(route('pm-review.show', $execution->id))
            ->assertSee('Standard Rentang')
            ->assertSee('Standard Pilihan Tunggal')
            ->assertSee('BERSIHKAN');
        $response->assertSee(route('pm-review.show', $execution->id));

        $this->assertSame(1, $response['rows']->total(), 'One occurrence must consume one parent pagination unit.');
        $this->assertSame([$execution->pm_schedule_date_id], $response['rows']->pluck('schedule_date_id')->all());
    }

    public function test_report_pm_invalid_date_range_shows_error_message(): void
    {
        $response = $this->actingAs($this->admin)->get('/report/pm?start_date=2026-05-10&end_date=2026-05-01');

        $response->assertSessionHasErrors('end_date');
        $response->assertRedirect();
    }

    public function test_report_pm_export_pdf_and_excel_create_report_exports_records(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/report/pm/export/pdf', [
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Report PM berhasil dibuat.')
            ->assertJsonStructure(['download_url']);

        $this->assertDatabaseHas('report_exports', [
            'report_type' => 'pm',
            'file_type' => 'pdf',
            'status' => 'completed',
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/report/pm/export/excel', [
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Report PM berhasil dibuat.')
            ->assertJsonStructure(['download_url']);

        $this->assertDatabaseHas('report_exports', [
            'report_type' => 'pm',
            'file_type' => 'excel',
            'status' => 'completed',
            'requested_by' => $this->admin->id,
        ]);
    }

    public function test_report_pm_export_without_data_returns_warning_response(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/report/pm/export/pdf', [
                'start_date' => now()->subYears(3)->toDateString(),
                'end_date' => now()->subYears(3)->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Tidak ada data PM untuk diexport.');
    }

    public function test_report_pm_export_respects_active_filter(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/report/pm/export/excel', [
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
                'machine_id' => $this->machineB->id,
                'warning' => 'warning',
                'status' => 'waiting_review',
            ])
            ->assertOk();

        $export = ReportExport::query()
            ->where('report_type', 'pm')
            ->where('file_type', 'excel')
            ->latest('id')
            ->first();

        $this->assertNotNull($export);
        $this->assertSame($this->machineB->id, $export->filter_data['machine_id']);
        $this->assertSame('warning', $export->filter_data['warning']);
        $this->assertSame('waiting_review', $export->filter_data['status']);
    }

    public function test_report_pm_export_includes_operator_note(): void
    {
        $service = app(ReportPmService::class);
        $rows = $service->exportRows([
            'machine_id' => $this->machineB->id,
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $row = $rows->firstWhere('machine_code', 'M-B-01');

        $this->assertNotNull($row);
        $this->assertSame('Perlu dicek ulang di shift malam', $row->operator_note);

        $export = new ReportPmExport(collect([$row]), $service);

        $this->assertContains('Catatan', $export->headings());
        $this->assertContains('Perlu dicek ulang di shift malam', $export->map($row));

        $view = $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups(collect([$row])),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => ['total' => 1],
            'printedBy' => $this->admin->name,
        ]);

        $view->assertSee('Catatan');
        $view->assertSee('Perlu dicek ulang di shift malam');
    }

    public function test_report_pm_pdf_preserves_frozen_multiple_action_selections_on_one_execution_item(): void
    {
        $fixture = $this->createMultipleActionExportFixture();
        $service = app(ReportPmService::class);
        $rows = $service->exportRows(['machine_id' => $this->machineA->id]);

        $this->assertSame(3, $fixture['execution']->items()->count());
        $this->assertCount(2, $fixture['multiple']->actionResults()->get());
        $this->assertNull($fixture['multiple']->action_value);
        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->where('execution_item_id', $fixture['multiple']->id));

        $html = (string) $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups($rows),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => [],
            'printedBy' => $this->admin->name,
        ]);
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new \DOMXPath($document);
        $singleRows = $xpath->query('//tr[td[normalize-space(.) = "Frozen Export Inspection"]]');
        $multipleRows = $xpath->query('//tr[td[normalize-space(.) = "Frozen Export Lubrication"]]');

        $this->assertCount(1, $singleRows);
        $this->assertStringContainsString('PASS', $singleRows->item(0)->textContent);
        $this->assertSame(1, substr_count($html, 'Frozen Export Lubrication'));
        $this->assertCount(1, $multipleRows);
        $multipleResult = $multipleRows->item(0)->textContent;
        $this->assertStringContainsString('CLEAN', $multipleResult, 'PDF kehilangan label hasil Action multiple beku pada item Lubrication.');
        $this->assertStringContainsString('LUBRICATE', $multipleResult);
        $this->assertLessThan(strpos($multipleResult, 'LUBRICATE'), strpos($multipleResult, 'CLEAN'));
    }

    public function test_report_pm_excel_preserves_frozen_multiple_action_selections_on_one_logical_row(): void
    {
        $fixture = $this->createMultipleActionExportFixture();
        $service = app(ReportPmService::class);
        $rows = $service->exportRows(['machine_id' => $this->machineA->id]);
        $export = new ReportPmExport($rows, $service);

        $this->assertSame(3, $fixture['execution']->items()->count());
        $this->assertCount(2, $fixture['multiple']->actionResults()->get());
        $this->assertNull($fixture['multiple']->action_value);
        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->where('execution_item_id', $fixture['multiple']->id));
        $mappedRows = $export->collection()->map(fn (object $row): array => $export->map($row));
        $this->assertCount(3, $mappedRows);
        $singleRows = $mappedRows->filter(fn (array $row): bool => $row[5] === 'Frozen Export Inspection')->values();
        $multipleRows = $mappedRows->filter(fn (array $row): bool => $row[5] === 'Frozen Export Lubrication')->values();

        $this->assertCount(1, $singleRows);
        $this->assertSame('PASS', $singleRows->first()[7]);
        $this->assertCount(1, $multipleRows);
        $multipleResult = $multipleRows->first()[7];
        $this->assertStringContainsString('CLEAN', $multipleResult, 'Excel kehilangan label hasil Action multiple beku pada baris Lubrication.');
        $this->assertStringContainsString('LUBRICATE', $multipleResult);
        $this->assertLessThan(strpos($multipleResult, 'LUBRICATE'), strpos($multipleResult, 'CLEAN'));
    }

    public function test_report_pm_excel_excludes_schedule_only_occurrences_from_detail_rows(): void
    {
        $fixture = $this->createMultipleActionExportFixture();
        $scheduleOnlyDate = now()->addDays(2)->toDateString();
        $this->createScheduleOnly($this->machineA, $scheduleOnlyDate, 'scheduled');
        $scheduleOnly = PmScheduleDate::query()
            ->where('machine_id', $this->machineA->id)
            ->whereDate('scheduled_date', $scheduleOnlyDate)
            ->firstOrFail();

        $service = app(ReportPmService::class);
        $rows = $service->exportRows(['machine_id' => $this->machineA->id]);
        $export = new ReportPmExport($rows, $service);
        $excelRows = $export->collection();
        $mappedRows = $excelRows
            ->map(fn (object $row): array => $export->map($row))
            ->values();
        $executionRows = $excelRows->where('execution_id', $fixture['execution']->id)->values();
        $executionItemIds = $fixture['execution']->items()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->assertCount(3, $executionItemIds);
        $this->assertCount(2, $fixture['multiple']->actionResults()->get());
        $this->assertSame(
            $executionItemIds,
            $executionRows->pluck('execution_item_id')->map(fn (int|string $id): int => (int) $id)->all(),
        );
        $multipleRows = $executionRows->where('execution_item_id', $fixture['multiple']->id);
        $this->assertCount(1, $multipleRows);
        $this->assertSame('CLEAN, LUBRICATE', $export->map($multipleRows->first())[7]);
        $scheduleOnlySourceRow = $rows->firstWhere('schedule_date_id', $scheduleOnly->id);
        $this->assertNotNull($scheduleOnlySourceRow);
        $this->assertNull($scheduleOnlySourceRow->execution_item_id);

        $mappedScheduleOnlyDates = $mappedRows
            ->filter(fn (array $row): bool => $row[0] === $scheduleOnlyDate)
            ->pluck(0)
            ->all();

        $this->assertSame(
            [
                'detail_rows' => 3,
                'schedule_only_dates' => [],
            ],
            [
                'detail_rows' => $mappedRows->count(),
                'schedule_only_dates' => $mappedScheduleOnlyDates,
            ],
            'Excel must contain the three execution-item rows and omit the schedule-only occurrence.',
        );
    }

    public function test_report_pm_export_failed_marks_report_export_failed(): void
    {
        Pdf::shouldReceive('loadView')
            ->once()
            ->andThrow(new \RuntimeException('Mock PDF gagal'));

        $this->actingAs($this->admin)
            ->postJson('/report/pm/export/pdf', [
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
            ])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Report PM gagal dibuat. Silakan coba kembali.');

        $this->assertDatabaseHas('report_exports', [
            'report_type' => 'pm',
            'file_type' => 'pdf',
            'status' => 'failed',
            'requested_by' => $this->admin->id,
        ]);
    }

    public function test_report_pm_pdf_keeps_six_standard_details_under_one_pm_job(): void
    {
        $fixture = $this->createPdfParentGrainFixture();
        $service = app(ReportPmService::class);
        $rows = $service->exportRows(['machine_id' => $this->machineA->id])
            ->where('execution_id', $fixture['execution']->id)
            ->values();

        $this->assertCount(6, $rows);

        $html = (string) $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups($rows),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => ['total' => 1, 'warning' => 1],
            'printedBy' => $this->admin->name,
        ]);
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new \DOMXPath($document);
        $table = $xpath->query('//table[.//th[normalize-space(.) = "Standard"] and .//th[normalize-space(.) = "Kode"]]')->item(0);
        $this->assertNotNull($table);

        $standardIndex = null;
        $jobNumberIndex = null;
        foreach ($xpath->query('.//thead/tr/th', $table) as $index => $header) {
            if (trim($header->textContent) === 'Standard') {
                $standardIndex = $index + 1;
            }
            if (trim($header->textContent) === 'No') {
                $jobNumberIndex = $index + 1;
            }
        }
        $this->assertNotNull($standardIndex);
        $this->assertNotNull($jobNumberIndex);

        $standards = [];
        $jobNumbers = [];
        foreach ($xpath->query('.//tbody/tr', $table) as $row) {
            $cells = $xpath->query('./td', $row);
            $standards[] = trim($cells->item($standardIndex - 1)->textContent);
            $jobNumbers[] = trim($cells->item($jobNumberIndex - 1)->textContent);
        }

        // Enam Standard tetap tampil tepat sekali, tanpa duplikasi atau kehilangan detail.
        $expectedStandards = $fixture['standards'];
        sort($standards);
        sort($expectedStandards);
        $this->assertSame($expectedStandards, $standards);

        // Kolom nomor adalah penghitung baris logis PDF; satu PM job harus
        // menghasilkan satu nomor, bukan enam nomor yang berarti enam PM.
        $distinctJobNumbers = array_values(array_unique($jobNumbers));
        $this->assertCount(1, $distinctJobNumbers, 'Satu PM job harus menjadi satu baris logis PDF, bukan enam baris terpisah.');
    }

    public function test_report_pm_pdf_summary_counts_occurrences_and_warnings_at_parent_grain(): void
    {
        $fixture = $this->createPdfParentGrainFixture();
        $service = app(ReportPmService::class);
        $rows = $service->exportRows(['machine_id' => $this->machineA->id])
            ->where('execution_id', $fixture['execution']->id)
            ->values();

        $this->assertCount(6, $rows);
        $this->assertSame(2, $rows->where('is_warning', true)->count());

        $captured = null;
        Pdf::shouldReceive('loadView')
            ->once()
            ->with('pages.report.pm.pdf', \Mockery::on(function (array $data) use (&$captured): bool {
                $captured = $data;

                return true;
            }))
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class, function ($mock): void {
                $mock->shouldReceive('setPaper')->andReturnSelf();
                $mock->shouldReceive('output')->andReturn('%PDF-1.4 fake');
            }));

        Storage::fake('public');
        app(ReportExportService::class)->exportPmPdf($this->admin, $rows, []);

        $this->assertSame(
            ['total' => 1, 'warning' => 1, 'approved' => 1],
            array_intersect_key($captured['summary'], array_flip(['total', 'warning', 'approved'])),
            'Ringkasan PDF harus menghitung Total PM dan Warning pada grain parent PM (Total=1, Warning=1).'
        );
        $this->assertSame(0, $captured['summary']['waiting_review']);

        // Render memakai parentGroups persis dari exportPmPdf, bukan proyeksi uji terpisah.
        $html = (string) $this->view('pages.report.pm.pdf', $captured);
        $this->assertSame(1, preg_match('/Total data:\s*1\s*\|\s*Warning:\s*1\s*\|/u', $html));
    }

    public function test_report_pm_pdf_range_result_uses_recorded_measurement_not_frozen_bounds(): void
    {
        $fixture = $this->createRangeResultExportFixture();
        $service = app(ReportPmService::class);
        $row = $service->exportRows(['machine_id' => $this->machineA->id])
            ->firstWhere('execution_item_id', $fixture['range']->id);

        $this->assertNotNull($row);
        // Rekaman 70 kPa sengaja berbeda dari kedua batas beku (10/80) agar hasil tidak ambigu.
        $this->assertSame(70.0, (float) $row->number_value);
        $this->assertSame(10.0, (float) $row->min_value_snapshot);
        $this->assertSame(80.0, (float) $row->max_value_snapshot);

        $html = (string) $this->view('pages.report.pm.pdf', [
            'parentGroups' => $this->pmPdfParentGroups(collect([$row])),
            'reportPmService' => $service,
            'filters' => [],
            'summary' => [],
            'printedBy' => $this->admin->name,
        ]);
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new \DOMXPath($document);
        $table = $xpath->query('//table[.//th[normalize-space(.) = "Standard"] and .//th[normalize-space(.) = "Nilai"]]')->item(0);
        $this->assertNotNull($table);

        $standardIndex = null;
        $resultIndex = null;
        $unitIndex = null;
        foreach ($xpath->query('.//thead/tr/th', $table) as $index => $header) {
            if (trim($header->textContent) === 'Standard') {
                $standardIndex = $index;
            }
            if (trim($header->textContent) === 'Nilai') {
                $resultIndex = $index;
            }
            if (trim($header->textContent) === 'Satuan') {
                $unitIndex = $index;
            }
        }
        $this->assertNotNull($standardIndex);
        $this->assertNotNull($resultIndex);
        $this->assertNotNull($unitIndex);

        $resultText = null;
        $unitText = null;
        foreach ($xpath->query('.//tbody/tr', $table) as $rowNode) {
            $cells = $xpath->query('./td', $rowNode);
            if (trim($cells->item($standardIndex)->textContent) === 'Pressure') {
                $resultText = trim($cells->item($resultIndex)->textContent);
                $unitText = trim($cells->item($unitIndex)->textContent);
                break;
            }
        }

        $this->assertNotNull($resultText, 'Baris Standard Pressure harus tampil pada PDF.');
        $this->assertSame('kPa', $unitText, 'Satuan PDF harus tetap kPa pada kolom Satuan terpisah.');
        $this->assertStringContainsString('70', $resultText, 'Hasil PDF Range harus memakai nilai terukur 70 kPa, bukan batas beku.');
        $this->assertDoesNotMatchRegularExpression('/^\s*10\s*[-–]\s*80\s*$/', $resultText, 'Hasil PDF Range tidak boleh memakai batas beku 10–80 kPa sebagai Result.');
    }

    public function test_report_pm_excel_range_result_uses_recorded_measurement_not_frozen_bounds(): void
    {
        $fixture = $this->createRangeResultExportFixture();
        $service = app(ReportPmService::class);
        $rows = $service->exportRows(['machine_id' => $this->machineA->id]);
        $export = new ReportPmExport($rows, $service);
        $resultIndex = array_search('Hasil Angka / Range', $export->headings(), true);
        $this->assertNotFalse($resultIndex);
        $unitIndex = array_search('Satuan', $export->headings(), true);
        $this->assertNotFalse($unitIndex);

        $rangeRow = $rows->firstWhere('execution_item_id', $fixture['range']->id);
        $this->assertNotNull($rangeRow);
        $this->assertSame(70.0, (float) $rangeRow->number_value);
        $this->assertSame(10.0, (float) $rangeRow->min_value_snapshot);
        $this->assertSame(80.0, (float) $rangeRow->max_value_snapshot);

        $mappedRange = $export->map($rangeRow);
        $this->assertSame('kPa', (string) $mappedRange[$unitIndex], 'Satuan Excel harus tetap kPa pada kolom Satuan terpisah.');
        $rangeCell = (string) $mappedRange[$resultIndex];
        $this->assertStringContainsString('70', $rangeCell, 'Hasil Excel Range harus memakai nilai terukur 70 kPa, bukan batas beku.');
        $this->assertDoesNotMatchRegularExpression('/^\s*10\s*[-–]\s*80\s*$/', $rangeCell, 'Hasil Excel Range tidak boleh memakai batas beku 10–80 kPa sebagai Result.');

        // Kontrol: item Number lama tetap memakai pengukuran langsung, membuktikan kegagalan spesifik Range.
        $numberRow = $rows->first(fn (object $row): bool => (string) $row->input_type_snapshot === 'number');
        $this->assertNotNull($numberRow);
        $this->assertSame('45', (string) $export->map($numberRow)[$resultIndex]);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function pmPdfParentGroups(Collection $rows): Collection
    {
        return app(ReportExportService::class)->pmParentGroups($rows);
    }

    /**
     * Satu execution memiliki tepat enam detail snapshot untuk uji grain PDF.
     *
     * @return array{execution: PmExecution, standards: list<string>}
     */
    private function createPdfParentGrainFixture(): array
    {
        $execution = PmExecution::query()
            ->where('machine_id', $this->machineA->id)
            ->firstOrFail();
        $execution->items()->delete();
        $standards = ['Temperature', 'Pressure', 'Condition', 'Lubrication', 'Oil Level', 'Leakage'];

        foreach ($standards as $index => $standard) {
            $execution->items()->create([
                'part_name_snapshot' => $index < 4 ? 'Bearing' : 'Gearbox',
                'standard_name_snapshot' => $standard,
                'input_type_snapshot' => 'number',
                'number_value' => $index < 2 ? 90 : 45,
                'min_value_snapshot' => 10,
                'max_value_snapshot' => 80,
                'unit_snapshot' => 'C',
                'is_warning' => $index < 2,
                'warning_message' => $index < 2 ? 'Warning value' : null,
            ]);
        }

        return ['execution' => $execution, 'standards' => $standards];
    }

    /**
     * Memakai satu execution fixture yang sudah ada tanpa mengubah lifecycle atau Master.
     *
     * @return array{execution: PmExecution, single: PmExecutionItem, multiple: PmExecutionItem}
     */
    private function createMultipleActionExportFixture(): array
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineA->id)->firstOrFail();
        $single = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Export Part',
            'standard_name_snapshot' => 'Frozen Export Inspection',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'single',
            'action_options_snapshot' => ['PASS', 'FAIL'],
            'action_value' => 'PASS',
            'is_warning' => false,
        ]);
        $multiple = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Export Part',
            'standard_name_snapshot' => 'Frozen Export Lubrication',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'multiple',
            'action_options_snapshot' => ['CLEAN', 'LUBRICATE'],
            'action_value' => null,
            'is_warning' => false,
        ]);

        // Urutan insert sengaja berlawanan; posisi snapshot menentukan urutan hasil export.
        $multiple->actionResults()->createMany([
            [
                'selected_label_snapshot' => 'LUBRICATE',
                'selected_label_identity_hash' => hash('sha256', 'LUBRICATE'),
                'option_position_snapshot' => 2,
            ],
            [
                'selected_label_snapshot' => 'CLEAN',
                'selected_label_identity_hash' => hash('sha256', 'CLEAN'),
                'option_position_snapshot' => 1,
            ],
        ]);

        return ['execution' => $execution, 'single' => $single, 'multiple' => $multiple];
    }

    /**
     * Fixture Range: rekaman terukur 70 kPa sengaja berbeda dari batas beku
     * 10–80 kPa agar kegagalan export memakai bounds sebagai Result tidak ambigu.
     *
     * @return array{execution: PmExecution, range: PmExecutionItem}
     */
    private function createRangeResultExportFixture(): array
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineA->id)->firstOrFail();
        $range = $execution->items()->create([
            'part_name_snapshot' => 'Frozen Range Part',
            'standard_name_snapshot' => 'Pressure',
            'input_type_snapshot' => 'range',
            'number_value' => 70,
            'min_value_snapshot' => 10,
            'max_value_snapshot' => 80,
            'unit_snapshot' => 'kPa',
            'is_warning' => false,
        ]);

        return ['execution' => $execution, 'range' => $range];
    }

    /**
     * Tujuh occurrence tersimpan; approved September hanya masuk melalui tanggal
     * historis. Lima item dan tiga Action terpilih tetap satu parent waiting_review.
     *
     * @return array{waiting_review: PmExecution, older_approved: PmExecution}
     */
    private function createSummaryFixture(): array
    {
        $execution = PmExecution::query()->where('machine_id', $this->machineB->id)->firstOrFail();
        $execution->items()->firstOrFail()->update(['target_value_snapshot' => 45]);
        $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Warning Temperature',
            'input_type_snapshot' => 'range',
            'number_value' => 90,
            'min_value_snapshot' => 10,
            'max_value_snapshot' => 80,
            'unit_snapshot' => 'C',
            'is_warning' => true,
            'warning_message' => 'Warning value',
        ]);
        $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Warning Pressure',
            'input_type_snapshot' => 'range',
            'number_value' => 9,
            'min_value_snapshot' => 1,
            'max_value_snapshot' => 8,
            'unit_snapshot' => 'bar',
            'is_warning' => true,
            'warning_message' => 'Warning value',
        ]);
        $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Single Inspection',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'single',
            'action_options_snapshot' => ['OK', 'NG'],
            'action_value' => 'OK',
            'is_warning' => false,
        ]);
        $multipleAction = $execution->items()->create([
            'part_name_snapshot' => 'Part B',
            'standard_name_snapshot' => 'Multiple Maintenance',
            'input_type_snapshot' => 'action',
            'selection_mode_snapshot' => 'multiple',
            'action_options_snapshot' => ['Inspect', 'Lubricate', 'Clean'],
            'is_warning' => false,
        ]);
        foreach (['Inspect', 'Lubricate', 'Clean'] as $position => $label) {
            $multipleAction->actionResults()->create([
                'selected_label_snapshot' => $label,
                'selected_label_identity_hash' => hash('sha256', $label),
                'option_position_snapshot' => $position + 1,
            ]);
        }

        // Status occurrence legacy tertinggal; summary harus mengikuti execution canonical.
        PmScheduleDate::query()->whereKey($execution->pm_schedule_date_id)->update(['status' => 'overdue']);
        $this->createScheduleOnly($this->machineA, '2026-09-30', 'overdue');
        $this->createScheduleOnly($this->machineA, '2026-10-01', 'missed');
        $this->createExecutionWithItem(
            machine: $this->machineA,
            scheduledDate: '2026-09-27',
            submittedAt: Carbon::parse('2026-09-27 10:30:00', 'Asia/Jakarta'),
            status: 'in_progress',
            scheduleStatus: 'in_progress',
            isWarning: false,
            partName: 'In Progress Summary Part',
        );
        PmExecution::query()->where('status', 'in_progress')->update(['submitted_at' => null]);
        $this->createExecutionWithItem(
            machine: $this->machineA,
            scheduledDate: '2026-09-25',
            submittedAt: Carbon::parse('2026-09-25 10:30:00', 'Asia/Jakarta'),
            status: 'approved',
            scheduleStatus: 'approved',
            isWarning: false,
            partName: 'Historical Summary Part',
        );
        $olderApproved = PmExecution::query()
            ->whereHas('scheduleDate', fn ($query) => $query->whereDate('scheduled_date', '2026-09-25'))
            ->firstOrFail();

        $this->assertSame(7, PmScheduleDate::query()->count());
        $this->assertSame(5, $execution->items()->count());
        $this->assertSame(3, $execution->items()->where('is_warning', true)->count());
        $this->assertSame(
            ['Inspect', 'Lubricate', 'Clean'],
            $multipleAction->actionResults()->orderBy('option_position_snapshot')->pluck('selected_label_snapshot')->all(),
        );

        return ['waiting_review' => $execution, 'older_approved' => $olderApproved];
    }

    /**
     * Membaca pasangan label/nilai tanpa menetapkan CSS, DTO atau metode produksi.
     * Opsi filter dan badge tanpa nilai numerik tidak dianggap summary.
     *
     * @param  array<string, int>  $expected
     */
    private function assertRenderedSummary(TestResponse $response, array $expected): void
    {
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new \DOMXPath($document);
        $actual = [];
        foreach (array_keys($expected) as $label) {
            $actual[$label] = null;
            $quotedLabel = preg_quote($label, '/');
            foreach ($xpath->query('//body//*[not(self::script or self::style) and not(ancestor-or-self::form or ancestor-or-self::select or descendant::select)]') as $element) {
                $text = trim(preg_replace('/\s+/u', ' ', $element->textContent));
                if (preg_match('/^(?:'.$quotedLabel.'\s*(\d+)|(\d+)\s*'.$quotedLabel.')$/u', $text, $matches)) {
                    $actual[$label] = (int) ($matches[1] !== '' ? $matches[1] : $matches[2]);
                    break;
                }
            }
        }

        $this->assertSame($expected, $actual, 'Summary numerik /report/pm harus menghitung parent pada scope tabel; null berarti pasangan label/nilai tidak dirender.');
    }

    protected function createExecutionWithItem(
        Machine $machine,
        string $scheduledDate,
        Carbon $submittedAt,
        string $status,
        string $scheduleStatus,
        bool $isWarning,
        string $partName,
        ?string $note = null,
    ): void {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CHK-'.$machine->machine_code.'-'.str_replace('-', '', $scheduledDate).'-'.substr((string) str()->uuid(), 0, 6),
            'checksheet_name' => 'Checksheet '.$machine->machine_name,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'daily',
            'start_date' => $scheduledDate,
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $scheduleDate = PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => $scheduledDate,
            'status' => $scheduleStatus,
            'status_changed_at' => now(),
        ]);

        $execution = PmExecution::query()->create([
            // Slice A: snapshot identitas transaksi.
            'machine_code_snapshot' => $machine->machine_code,
            'machine_name_snapshot' => $machine->machine_name,
            'location_code_snapshot' => Location::query()->findOrFail($machine->location_id)->location_code,
            'location_name_snapshot' => Location::query()->findOrFail($machine->location_id)->location_name,
            'pm_schedule_date_id' => $scheduleDate->id,
            'machine_id' => $machine->id,
            'operator_id' => $this->operator->id,
            'operator_name_snapshot' => $this->operator->name,
            'status' => $status,
            'started_at' => $submittedAt->copy()->subMinutes(30),
            'submitted_at' => $submittedAt,
            'approved_at' => $status === 'approved' ? $submittedAt->copy()->addHours(2) : null,
            'approved_by' => $status === 'approved' ? $this->admin->id : null,
            'approved_by_name_snapshot' => $status === 'approved' ? $this->admin->name : null,
        ]);

        PmExecutionItem::query()->create([
            'pm_execution_id' => $execution->id,
            'part_name_snapshot' => $partName,
            'standard_name_snapshot' => 'Standard '.$partName,
            'input_type_snapshot' => 'number',
            'number_value' => $isWarning ? 90 : 45,
            'min_value_snapshot' => 10,
            'max_value_snapshot' => 80,
            'unit_snapshot' => 'C',
            'is_warning' => $isWarning,
            'warning_message' => $isWarning ? 'Warning value' : null,
            'note' => $note,
        ]);
    }

    protected function createScheduleOnly(Machine $machine, string $scheduledDate, string $status): void
    {
        $checksheet = PmChecksheet::query()->create([
            'checksheet_code' => 'CHK-SCHEDULE-'.$machine->machine_code.'-'.str_replace('-', '', $scheduledDate),
            'checksheet_name' => 'Checksheet '.$machine->machine_name,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $checksheetMachine = PmChecksheetMachine::query()->create([
            'pm_checksheet_id' => $checksheet->id,
            'machine_id' => $machine->id,
            'created_by' => $this->admin->id,
        ]);

        $schedule = PmSchedule::query()->create([
            'pm_checksheet_machine_id' => $checksheetMachine->id,
            'frequency_type' => 'daily',
            'start_date' => $scheduledDate,
            'generate_until' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        PmScheduleDate::query()->create([
            'pm_schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'scheduled_date' => $scheduledDate,
            'status' => $status,
            'status_changed_at' => now(),
        ]);
    }
}
