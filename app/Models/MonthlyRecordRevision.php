<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['monthly_record_id', 'actor_id', 'reason', 'before', 'after'])]
class MonthlyRecordRevision extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
