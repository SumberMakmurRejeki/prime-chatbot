<?php

namespace Tests\Unit\Services\PM;

use App\Services\PM\PmScheduleDateGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PmScheduleDateGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function sameBusinessDateBoundaries(): array
    {
        return [
            'strings' => ['2026-10-30', '2026-10-31'],
            'carbon utc' => [
                Carbon::parse('2026-10-30', 'UTC'),
                Carbon::parse('2026-10-31', 'UTC'),
            ],
            'carbon jakarta' => [
                Carbon::parse('2026-10-30', 'Asia/Jakarta'),
                Carbon::parse('2026-10-31', 'Asia/Jakarta'),
            ],
            'carbon utc including end of day' => [
                Carbon::parse('2026-10-30', 'UTC'),
                Carbon::parse('2026-10-31', 'UTC')->endOfDay(),
            ],
            'carbon immutable' => [
                CarbonImmutable::parse('2026-10-30', 'UTC'),
                CarbonImmutable::parse('2026-10-31', 'UTC'),
            ],
            'date time interfaces' => [
                new DateTimeImmutable('2026-10-30 05:00:00', new DateTimeZone('UTC')),
                new DateTime('2026-10-31 18:30:00', new DateTimeZone('Asia/Jakarta')),
            ],
            'mixed string and carbon' => [
                '2026-10-30',
                Carbon::parse('2026-10-31', 'Asia/Jakarta'),
            ],
        ];
    }

    #[DataProvider('sameBusinessDateBoundaries')]
    public function test_daily_schedule_depends_only_on_business_calendar_dates(mixed $start, mixed $end): void
    {
        $dates = PmScheduleDateGenerator::generate([
            'frequency_type' => 'daily',
            'start_date' => $start,
            'generate_until' => $end,
        ]);

        $this->assertSame(['2026-10-30', '2026-10-31'], $dates);
    }

    public function test_monthly_final_occurrence_stays_inclusive_for_carbon_end(): void
    {
        $dates = PmScheduleDateGenerator::generate([
            'frequency_type' => 'monthly',
            'monthly_day' => 1,
            'start_date' => '2026-08-01',
            'generate_until' => Carbon::parse('2026-10-01', 'Asia/Jakarta'),
        ]);

        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $dates);
    }

    public function test_weekly_final_occurrence_stays_inclusive_for_carbon_end(): void
    {
        $dates = PmScheduleDateGenerator::generate([
            'frequency_type' => 'weekly',
            'weekly_days' => [1],
            'start_date' => '2026-09-07',
            'generate_until' => Carbon::parse('2026-10-05', 'Asia/Jakarta'),
        ]);

        $this->assertSame(
            ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'],
            $dates,
        );
    }

    public function test_start_behind_utc_is_not_dropped_by_string_end(): void
    {
        $dates = PmScheduleDateGenerator::generate([
            'frequency_type' => 'daily',
            'start_date' => Carbon::parse('2026-10-31', 'America/New_York'),
            'generate_until' => '2026-10-31',
        ]);

        $this->assertSame(['2026-10-31'], $dates);
    }

    public function test_monthly_day_is_clamped_to_month_end(): void
    {
        $dates = PmScheduleDateGenerator::generate([
            'frequency_type' => 'monthly',
            'monthly_day' => 31,
            'start_date' => '2026-09-01',
            'generate_until' => Carbon::parse('2026-11-30', 'Asia/Jakarta'),
        ]);

        $this->assertSame(['2026-09-30', '2026-10-31', '2026-11-30'], $dates);
    }

    public function test_inverted_range_returns_no_dates(): void
    {
        $dates = PmScheduleDateGenerator::generate([
            'frequency_type' => 'daily',
            'start_date' => '2026-11-05',
            'generate_until' => Carbon::parse('2026-10-31', 'Asia/Jakarta'),
        ]);

        $this->assertSame([], $dates);
    }

    public function test_caller_owned_carbon_instances_are_not_mutated(): void
    {
        $start = Carbon::parse('2026-10-30 23:59:59', 'Asia/Jakarta');
        $end = Carbon::parse('2026-10-31 00:30:00', 'UTC');

        PmScheduleDateGenerator::generate([
            'frequency_type' => 'daily',
            'start_date' => $start,
            'generate_until' => $end,
        ]);

        $this->assertSame('2026-10-30 23:59:59 +07:00', $start->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-10-31 00:30:00 +00:00', $end->format('Y-m-d H:i:s P'));
    }
}
