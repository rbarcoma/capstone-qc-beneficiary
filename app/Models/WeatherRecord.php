<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon $month */
#[Fillable(['location_key', 'source', 'month', 'latitude', 'longitude', 'weather_values', 'units', 'quality', 'is_complete', 'raw_values', 'source_metadata', 'content_hash', 'fetched_at'])]
class WeatherRecord extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d', 'latitude' => 'decimal:6', 'longitude' => 'decimal:6',
            'weather_values' => 'array', 'units' => 'array', 'quality' => 'array',
            'is_complete' => 'boolean', 'raw_values' => 'array', 'source_metadata' => 'array', 'fetched_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->only(['id', 'source', 'location_key', 'month', 'latitude', 'longitude', 'weather_values', 'units', 'quality', 'is_complete', 'raw_values', 'source_metadata', 'content_hash', 'fetched_at']);
    }
}
