<?php

namespace App\Services\PM;

use Carbon\Carbon;
use DateTimeInterface;

class PmScheduleDateGenerator
{
    /**
     * @param  array<string, mixed>  $schedule
     * @return array<int, string>
     */
    public static function generate(array $schedule): array
    {
        $start = self::normalizeBusinessDate($schedule['start_date']);
        $end = self::normalizeBusinessDate($schedule['generate_until']);

        $dates = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $shouldInclude = false;

            if ($schedule['frequency_type'] === 'daily') {
                $shouldInclude = true;
            }

            if ($schedule['frequency_type'] === 'weekly') {
                $selectedDays = array_map('intval', $schedule['weekly_days'] ?? []);
                $shouldInclude = in_array((int) $cursor->dayOfWeek, $selectedDays, true);
            }

            if ($schedule['frequency_type'] === 'monthly') {
                $targetDay = max(1, min(31, (int) ($schedule['monthly_day'] ?? 1)));
                $validDay = min($targetDay, $cursor->copy()->endOfMonth()->day);
                $shouldInclude = $cursor->day === $validDay;
            }

            if ($shouldInclude) {
                $dates[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return array_values(array_unique($dates));
    }

    private static function normalizeBusinessDate(string|DateTimeInterface $date): Carbon
    {
        // Schedule boundaries are calendar dates, not technical instants.
        $calendarDate = $date instanceof DateTimeInterface
            ? $date->format('Y-m-d')
            : Carbon::parse($date, 'Asia/Jakarta')->toDateString();

        return Carbon::parse($calendarDate, 'Asia/Jakarta')->startOfDay();
    }
}
