<?php

namespace Tests\Feature\Feature\Report;

use App\Exports\Breakdown\ReportBreakdownExport;
use App\Exports\PM\ReportPmExport;
use App\Exports\PM\ReportPmWorkerExport;
use App\Models\Breakdown;
use App\Services\Report\ReportBreakdownService;
use App\Services\Report\ReportPmExportSpool;
use App\Services\Report\ReportPmService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Regresi keamanan sel XLSX: teks bawaan pengguna harus tetap sel teks literal,
 * tidak pernah diketik sebagai formula oleh PhpSpreadsheet.
 */
class ReportExportTextSafetyTest extends TestCase
{
    public function test_pm_excel_export_stores_formula_like_text_as_literal_text_cells(): void
    {
        Storage::fake('public');
        $rows = collect([(object) [
            'schedule_date_id' => 1,
            'scheduled_date' => '2026-10-04',
            'schedule_status' => 'approved',
            'execution_id' => 2,
            'execution_status' => 'approved',
            'execution_item_id' => 3,
            'location_name' => '=1+1',
            'machine_code' => 'MC-01',
            'machine_name' => '=HYPERLINK("http://evil.example","klik")',
            'part_name_snapshot' => 'Part A',
            'standard_name_snapshot' => 'Periksa tekanan',
            'input_type_snapshot' => 'number',
            'multiple_action_labels' => null,
            'action_value' => null,
            'number_value' => '42.5',
            'unit_snapshot' => 'bar',
            'is_warning' => false,
            'operator_note' => "=cmd|' /C calc'!A0",
            'operator_name_snapshot' => 'Operator',
            'approved_by_name_snapshot' => 'Admin',
            'submitted_at' => '2026-10-04 08:00:00',
            'approved_at' => '2026-10-04 09:00:00',
        ]]);

        Excel::store(new ReportPmExport($rows, app(ReportPmService::class)), 'pm-text-safety.xlsx', 'public');

        $sheet = IOFactory::load(Storage::disk('public')->path('pm-text-safety.xlsx'))->getActiveSheet();

        // Struktur kolom lama tetap: heading 17 kolom, satu baris data.
        $this->assertCount(2, $sheet->toArray());
        $this->assertSame(17, count($sheet->toArray()[0]));
        $this->assertSame('Catatan', $sheet->getCell('L1')->getValue());

        $this->assertLiteralTextCell($sheet, 'B2', '=1+1');
        $this->assertLiteralTextCell($sheet, 'D2', '=HYPERLINK("http://evil.example","klik")');
        $this->assertLiteralTextCell($sheet, 'L2', "=cmd|' /C calc'!A0");

        // Kolom numerik dan label biasa tidak boleh berubah tipe.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I2')->getDataType());
        $this->assertSame(42.5, $sheet->getCell('I2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame('2026-10-04', $sheet->getCell('A2')->getValue());
        $this->assertSame('Periksa tekanan', $sheet->getCell('F2')->getValue());
    }

    public function test_breakdown_excel_export_stores_formula_like_text_as_literal_text_cells(): void
    {
        Storage::fake('public');
        $breakdown = new Breakdown([
            'breakdown_code' => '=1+1',
            'machine_name_snapshot' => 'Mesin =SUM(A1:A2)',
            'problem' => '=WEBSERVICE("http://evil.example")',
            'root_cause' => 'Bearing aus',
            'action_taken' => '=2+2',
            'countermeasure' => 'Ganti bearing',
            'status' => 'closed',
            'breakdown_at' => Carbon::parse('2026-10-04 08:00:00'),
            'closed_at' => Carbon::parse('2026-10-04 10:00:00'),
            'created_by_name_snapshot' => '=TODAY()',
            'closed_by_name_snapshot' => 'Admin',
            'downtime_minutes' => 120,
        ]);
        $breakdown->setRelation('machine', null);

        Excel::store(new ReportBreakdownExport(collect([$breakdown]), app(ReportBreakdownService::class)), 'breakdown-text-safety.xlsx', 'public');

        $sheet = IOFactory::load(Storage::disk('public')->path('breakdown-text-safety.xlsx'))->getActiveSheet();

        // Struktur kolom lama tetap: heading 14 kolom, satu baris data.
        $this->assertCount(2, $sheet->toArray());
        $this->assertSame(14, count($sheet->toArray()[0]));
        $this->assertSame('Problem', $sheet->getCell('F1')->getValue());

        $this->assertLiteralTextCell($sheet, 'B2', '=1+1');
        $this->assertLiteralTextCell($sheet, 'E2', 'Mesin =SUM(A1:A2)');
        $this->assertLiteralTextCell($sheet, 'F2', '=WEBSERVICE("http://evil.example")');
        $this->assertLiteralTextCell($sheet, 'H2', '=2+2');
        $this->assertLiteralTextCell($sheet, 'K2', '=TODAY()');

        // Kolom non-formula tetap seperti kontrak lama.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
        $this->assertSame(1, $sheet->getCell('A2')->getValue());
        $this->assertSame('Bearing aus', $sheet->getCell('G2')->getValue());
        $this->assertSame('Ganti bearing', $sheet->getCell('I2')->getValue());
        $this->assertSame('CLOSED', $sheet->getCell('M2')->getValue());
        $this->assertSame('2 jam', $sheet->getCell('N2')->getValue());
    }

    public function test_pm_worker_excel_export_stores_formula_like_text_as_literal_text_cells(): void
    {
        Storage::fake('public');
        $spool = ReportPmExportSpool::open(920001, ['report_export_id' => 920001]);
        $spool->writeParent([
            'schedule_date_id' => 1, 'scheduled_date' => '2026-10-04', 'schedule_status' => 'approved',
            'execution_id' => 2, 'execution_status' => 'approved', 'machine_code' => 'MC-SPOOL',
            'machine_name' => '=1+1', 'location_name' => '=HYPERLINK("http://evil.example","klik")',
            'operator_name_snapshot' => 'Operator', 'approved_by_name_snapshot' => 'Admin',
            'submitted_at' => null, 'approved_at' => null, 'is_warning' => false,
            'has_deleted_execution' => false, 'schedule_only' => false,
        ]);
        $spool->writeDetail([
            'schedule_date_id' => 1, 'execution_id' => 2, 'execution_item_id' => 3,
            'part_name_snapshot' => 'Part Spool', 'standard_name_snapshot' => 'Standard Spool',
            'input_type_snapshot' => 'number', 'action_value' => null, 'number_value' => '42.00',
            'min_value_snapshot' => null, 'max_value_snapshot' => null, 'unit_snapshot' => 'C',
            'is_warning' => false, 'operator_note' => "=cmd|' /C calc'!A0",
        ]);
        $readyPath = $spool->finalize();
        $readySpool = ReportPmExportSpool::openReady($readyPath, 920001);

        try {
            Excel::store(new ReportPmWorkerExport($readySpool, app(ReportPmService::class)), 'pm-worker-text-safety.xlsx', 'public');

            $sheet = IOFactory::load(Storage::disk('public')->path('pm-worker-text-safety.xlsx'))->getActiveSheet();

            $this->assertSame(17, count($sheet->toArray()[0]));
            $this->assertLiteralTextCell($sheet, 'D2', '=1+1');
            $this->assertLiteralTextCell($sheet, 'B2', '=HYPERLINK("http://evil.example","klik")');
            $this->assertLiteralTextCell($sheet, 'L2', "=cmd|' /C calc'!A0");
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I2')->getDataType());
            $this->assertSame(42.0, (float) $sheet->getCell('I2')->getValue());
        } finally {
            $readySpool->delete();
        }
    }

    public function test_unicode_and_ordinary_pm_text_survive_export_unchanged(): void
    {
        Storage::fake('public');
        $note = 'Catatan: tekanan 2,5 bar — aman ✅ (\=1+1)';
        $rows = collect([(object) [
            'schedule_date_id' => 1,
            'scheduled_date' => '2026-10-04',
            'schedule_status' => 'approved',
            'execution_id' => 2,
            'execution_status' => 'approved',
            'execution_item_id' => 3,
            'location_name' => 'Line Produksi A',
            'machine_code' => 'MC-01',
            'machine_name' => 'Mesin Hydrolik §7',
            'part_name_snapshot' => 'Part A',
            'standard_name_snapshot' => 'Periksa oli',
            'input_type_snapshot' => 'action',
            'multiple_action_labels' => null,
            'action_value' => 'BERSIH',
            'number_value' => null,
            'unit_snapshot' => null,
            'is_warning' => true,
            'operator_note' => $note,
            'operator_name_snapshot' => 'Operator',
            'approved_by_name_snapshot' => 'Admin',
            'submitted_at' => '2026-10-04 08:00:00',
            'approved_at' => '2026-10-04 09:00:00',
        ]]);

        Excel::store(new ReportPmExport($rows, app(ReportPmService::class)), 'pm-unicode-safety.xlsx', 'public');

        $sheet = IOFactory::load(Storage::disk('public')->path('pm-unicode-safety.xlsx'))->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('L2')->getDataType());
        $this->assertSame($note, $sheet->getCell('L2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('H2')->getDataType());
        $this->assertSame('BERSIH', $sheet->getCell('H2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('J2')->getDataType());
        $this->assertSame('-', $sheet->getCell('J2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('K2')->getDataType());
        $this->assertSame('Warning', $sheet->getCell('K2')->getValue());
    }

    private function assertLiteralTextCell(Worksheet $sheet, string $coordinate, string $expected): void
    {
        $cell = $sheet->getCell($coordinate);

        $this->assertSame(
            DataType::TYPE_STRING,
            $cell->getDataType(),
            sprintf('Sel %s harus sel teks literal, bukan formula.', $coordinate),
        );
        $this->assertSame($expected, $cell->getValue());
    }
}
