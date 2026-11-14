<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $cutoff_month
 * @property array<string, mixed> $input_manifest
 */
#[Fillable(['building_id', 'version', 'pipeline_version', 'cutoff_month', 'fingerprint', 'input_manifest', 'issues', 'rows_count', 'weather_complete_count', 'created_by'])]
class Dataset extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cutoff_month' => 'date:Y-m-d', 'input_manifest' => 'array', 'issues' => 'array'];
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return HasMany<ProcessedRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(ProcessedRecord::class);
    }
}
