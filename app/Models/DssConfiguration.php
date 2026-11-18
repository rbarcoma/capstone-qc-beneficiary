<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $source_snapshot
 * @property array<string, mixed>|null $source_confirmation
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class DssConfiguration extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'source_snapshot' => 'array', 'source_confirmation' => 'array', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $item) {
            if (array_diff(array_keys($item->getDirty()), ['state_version', 'status', 'source_confirmation', 'reviewer_id', 'approval_reference', 'approved_hash', 'updated_at'])) {
                throw new LogicException('Configuration content is immutable. Draft a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Configuration review history is retained.'));
    }
}
