<?php

namespace App\Models;

use Database\Factories\MeterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $building_id
 * @property string $meralco_account_ref
 * @property string|null $tariff_class
 * @property string $status
 */
#[Fillable(['building_id', 'meralco_account_ref', 'tariff_class', 'status'])]
class Meter extends Model
{
    /** @use HasFactory<MeterFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return HasMany<MonthlyRecord, $this> */
    public function monthlyRecords(): HasMany
    {
        return $this->hasMany(MonthlyRecord::class);
    }

    /**
     * Masked form for display. The raw reference never leaves the backend
     * (proposal §9.1: restrict viewing of account numbers; frame shows
     * "Masked in UI").
     */
    public function maskedRef(): string
    {
        return strlen($this->meralco_account_ref) > 4
            ? '•••• '.substr($this->meralco_account_ref, -4)
            : '••••';
    }
}
