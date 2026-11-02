<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PmChecksheetStandard extends Model
{
    use HasFactory;

    protected $fillable = [
        'pm_checksheet_part_id',
        'standard_name',
        'input_type',
        'selection_mode',
        'action_options',
        'target_value',
        'min_value',
        'max_value',
        'unit',
        'description',
        'is_required',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'action_options' => 'array',
            'target_value' => 'decimal:2',
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Mode legacy Action tanpa nilai eksplisit tetap memilih satu hasil.
     */
    public function effectiveSelectionMode(): ?string
    {
        return $this->input_type === 'action' ? ($this->selection_mode ?? 'single') : null;
    }

    /**
     * Subset Master Action terpilih (V1). Urutan pivot adalah source of truth;
     * kolom legacy action_options hanya proyeksi kompatibilitas untuk data lama.
     */
    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(PmAction::class, 'pm_checksheet_standard_actions', 'pm_checksheet_standard_id', 'pm_action_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(PmChecksheetPart::class, 'pm_checksheet_part_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): void
    {
        $query->where('is_active', false);
    }
}
