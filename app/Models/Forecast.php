<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $training_end_month
 * @property Carbon $target_month
 * @property string $predicted_kwh
 * @property string $baseline_kwh
 * @property string $lower_kwh
 * @property string $upper_kwh
 * @property array<string, mixed> $worker_result
 */
#[Fillable(['building_id', 'dataset_id', 'issuance_key', 'dataset_fingerprint', 'engine_signature', 'model_version', 'model_name', 'training_end_month', 'target_month', 'predicted_kwh', 'baseline_kwh', 'lower_kwh', 'upper_kwh', 'worker_result', 'issued_by', 'issued_at'])]
class Forecast extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['training_end_month' => 'date:Y-m-d', 'target_month' => 'date:Y-m-d', 'predicted_kwh' => 'decimal:3', 'baseline_kwh' => 'decimal:3', 'lower_kwh' => 'decimal:3', 'upper_kwh' => 'decimal:3', 'worker_result' => 'array', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** @return HasMany<ForecastResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ForecastResult::class);
    }

    /** @return HasOne<ForecastError, $this> */
    public function actualError(): HasOne
    {
        return $this->hasOne(ForecastError::class);
    }
}
