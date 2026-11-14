<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['forecast_id', 'month', 'actual_kwh', 'predicted_kwh', 'baseline_kwh', 'train_end_month', 'training_rows'])]
class ForecastResult extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'train_end_month' => 'date:Y-m-d', 'actual_kwh' => 'decimal:3', 'predicted_kwh' => 'decimal:3', 'baseline_kwh' => 'decimal:3'];
    }
}
