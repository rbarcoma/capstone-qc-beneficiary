<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['forecast_id', 'valid', 'reason', 'actual_kwh', 'absolute_error', 'squared_error', 'percentage_error', 'source_snapshot', 'state_hash', 'evaluated_at'])]
class ForecastError extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid' => 'boolean', 'actual_kwh' => 'decimal:3', 'source_snapshot' => 'array', 'evaluated_at' => 'datetime'];
    }
}
