<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DssConfigurationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review events are immutable.'));
        static::deleting(fn () => throw new LogicException('Review events are retained.'));
    }
}
