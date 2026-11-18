<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['provider', 'account_reference'])]
class UtilityAccount extends Model
{
    protected $hidden = ['account_reference'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Utility identities are retained; remapping requires a separately reviewed contract.'));
        static::deleting(fn () => throw new LogicException('Utility identities are retained.'));
    }
}
