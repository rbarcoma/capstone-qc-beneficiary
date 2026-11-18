<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $amount_php
 * @property string|null $quantity
 * @property string|null $printed_rate
 */
class BillingEvidenceComponent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['label'];

    protected function casts(): array
    {
        return ['is_subtotal' => 'boolean', 'quantity' => 'decimal:6', 'printed_rate' => 'decimal:8', 'amount_php' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $component) {
            if (BillingEvidence::findOrFail($component->billing_evidence_id)->verification_status !== 'draft') {
                throw new LogicException('Verified component evidence is immutable.');
            }
        });
        static::updating(fn () => throw new LogicException('Components are append-only within a new evidence version.'));
        static::deleting(fn () => throw new LogicException('Components are retained with their evidence version.'));
    }
}
