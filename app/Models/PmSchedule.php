<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PmSchedule extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'pm_checksheet_machine_id',
        'frequency_type',
        'weekly_days',
        'monthly_day',
        'operational_from',
        'start_date',
        'generate_until',
        'is_active',
        'lifecycle_status',
        'effective_live_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weekly_days' => 'array',
            'monthly_day' => 'integer',
            'operational_from' => 'date',
            'start_date' => 'date',
            'generate_until' => 'date',
            'is_active' => 'boolean',
            'effective_live_from' => 'date',
        ];
    }

    public function checksheetMachine(): BelongsTo
    {
        return $this->belongsTo(PmChecksheetMachine::class, 'pm_checksheet_machine_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scheduleDates(): HasMany
    {
        return $this->hasMany(PmScheduleDate::class);
    }

    public function pauseIntervals(): HasMany
    {
        return $this->hasMany(PmSchedulePauseInterval::class)->orderBy('id');
    }

    public function isPausedOn(string $date): bool
    {
        return $this->pauseIntervals->contains(
            fn (PmSchedulePauseInterval $interval): bool => $interval->paused_on->toDateString() <= $date
                && ($interval->resumed_on === null || $date < $interval->resumed_on->toDateString()),
        );
    }

    /**
     * Hitung union interval pada [due, today), bukan durasi timestamp/DST.
     */
    public function activeAge(string $due, string $today): int
    {
        $start = Carbon::parse($due, 'UTC')->startOfDay();
        $end = Carbon::parse($today, 'UTC')->startOfDay();
        if ($start->gte($end)) {
            return 0;
        }

        $pausedDays = 0;
        $coveredUntil = $due;
        foreach ($this->pauseIntervals->sortBy('paused_on') as $interval) {
            $from = max($due, $coveredUntil, $interval->paused_on->toDateString());
            $until = min($today, $interval->resumed_on?->toDateString() ?? $today);
            if ($from < $until) {
                $pausedDays += (int) Carbon::parse($from, 'UTC')->diffInDays(Carbon::parse($until, 'UTC'));
                $coveredUntil = $until;
            }
        }

        return (int) $start->diffInDays($end) - $pausedDays;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): void
    {
        $query->where('is_active', false);
    }

    /**
     * Membatasi query pada satu era operasional non-terminal yang masih berlaku.
     */
    public function scopeCurrentEra(Builder $query): void
    {
        $query->whereIn('lifecycle_status', ['active', 'paused']);
    }

    public function scopeByDateRange(Builder $query, string $startDate, string $endDate): void
    {
        $query->whereDate('start_date', '>=', $startDate)
            ->whereDate('generate_until', '<=', $endDate);
    }
}
