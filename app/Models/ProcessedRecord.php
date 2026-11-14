<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $month
 * @property string $kwh
 * @property string|null $cost_php
 * @property array<string, mixed> $metrics
 * @property array<string, mixed> $features
 * @property array<string, mixed>|null $weather_snapshot
 */
#[Fillable(['dataset_id', 'month', 'kwh', 'cost_php', 'source_snapshot', 'weather_record_id', 'weather_snapshot', 'weather_complete', 'features', 'metrics'])]
class ProcessedRecord extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d', 'kwh' => 'decimal:3', 'cost_php' => 'decimal:2',
            'source_snapshot' => 'array', 'weather_snapshot' => 'array', 'weather_complete' => 'boolean',
            'features' => 'array', 'metrics' => 'array',
        ];
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
