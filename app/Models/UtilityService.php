<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['utility_account_id', 'meter_id', 'service_reference', 'physical_meter_reference', 'mapping_reference'])]
class UtilityService extends Model
{
    protected $hidden = ['service_reference', 'physical_meter_reference', 'mapping_reference'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Service/meter mappings are immutable in this bounded phase.'));
        static::deleting(fn () => throw new LogicException('Service/meter mappings are retained.'));
    }
}
