<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PmScheduleDate extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'pm_schedule_id',
        'machine_id',
        'scheduled_date',
        'status',
        'status_changed_at',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'status_changed_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PmSchedule::class, 'pm_schedule_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(PmExecution::class);
    }

    /**
     * ADR-004: hubungan singular identitas canonical untuk satu occurrence.
     *
     * withTrashed dipakai agar soft-deleted execution tetap menjadi identitas historis
     * yang memblokir replacement, sesuai invariant "maksimal satu canonical execution
     * termasuk soft-deleted row" dari ADR-004.
     */
    public function canonicalExecution(): HasOne
    {
        return $this->hasOne(PmExecution::class)->withTrashed();
    }

    public function scopeWithoutPauseGap(Builder $query): void
    {
        self::constrainPauseGap($query);
    }

    /**
     * Gap hanya membebaskan occurrence mutable tanpa execution, bukan histori.
     */
    public static function constrainPauseGap(Builder|QueryBuilder $query, string $table = 'pm_schedule_dates'): void
    {
        $query->where(function ($eligible) use ($table): void {
            $eligible->whereNotIn($table.'.status', ['scheduled', 'overdue', 'missed'])
                ->orWhereExists(function (QueryBuilder $execution) use ($table): void {
                    $execution->selectRaw('1')->from('pm_executions as pause_execution')
                        ->whereColumn('pause_execution.pm_schedule_date_id', $table.'.id');
                })
                ->orWhereNotExists(function (QueryBuilder $interval) use ($table): void {
                    $interval->selectRaw('1')->from('pm_schedule_pause_intervals as pause_interval')
                        ->whereColumn('pause_interval.pm_schedule_id', $table.'.pm_schedule_id')
                        ->whereRaw('DATE(pause_interval.paused_on) <= DATE('.$table.'.scheduled_date)')
                        ->where(function (QueryBuilder $end) use ($table): void {
                            $end->whereNull('pause_interval.resumed_on')
                                ->orWhereRaw('DATE(pause_interval.resumed_on) > DATE('.$table.'.scheduled_date)');
                        });
                });
        });
    }

    public function scopeByStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    public function scopeByDateRange(Builder $query, string $startDate, string $endDate): void
    {
        $query->whereBetween('scheduled_date', [$startDate, $endDate]);
    }
}
