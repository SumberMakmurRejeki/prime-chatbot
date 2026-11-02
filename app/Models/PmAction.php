<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PmAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Inverse subset action standard. Pivot position menentukan urutan tampil di
     * wizard dan urutan snapshot; relasi ini diurutkan agar konsisten dengan
     * PmChecksheetStandard::actions().
     */
    public function standards(): BelongsToMany
    {
        return $this->belongsToMany(PmChecksheetStandard::class, 'pm_checksheet_standard_actions', 'pm_action_id', 'pm_checksheet_standard_id')
            ->withPivot('position')
            ->orderByPivot('position');
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
