<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Decimal casts deliberately return strings, never binary floating-point money.
 *
 * @property string|null $import_kwh
 * @property string|null $export_kwh
 * @property string|null $renewable_kwh
 * @property string|null $own_use_kwh
 * @property string|null $energy_amount_php
 * @property string|null $export_credit_php
 * @property string|null $other_charges_php
 * @property string|null $applied_credits_php
 * @property string|null $current_charges_php
 * @property string|null $previous_balance_php
 * @property string|null $total_due_php
 * @property list<int> $source_pages
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $bill_date
 */
class BillingEvidence extends Model
{
    protected $table = 'billing_evidence';

    protected $guarded = ['*'];

    protected $hidden = ['source_document', 'document_hash', 'revision_reason', 'source_fingerprint', 'author_id', 'verified_by', 'verified_fingerprint'];

    protected function casts(): array
    {
        return [
            'source_pages' => 'array', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'bill_date' => 'date:Y-m-d', 'verified_at' => 'datetime',
            'import_kwh' => 'decimal:3', 'export_kwh' => 'decimal:3', 'renewable_kwh' => 'decimal:3', 'own_use_kwh' => 'decimal:3',
            'energy_amount_php' => 'decimal:2', 'export_credit_php' => 'decimal:2', 'other_charges_php' => 'decimal:2', 'applied_credits_php' => 'decimal:2',
            'current_charges_php' => 'decimal:2', 'previous_balance_php' => 'decimal:2', 'total_due_php' => 'decimal:2',
        ];
    }

    /** @return HasMany<BillingEvidenceComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(BillingEvidenceComponent::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $evidence) {
            $allowed = ['verification_status', 'verified_by', 'verified_at', 'verified_fingerprint', 'updated_at'];
            if ($evidence->getRawOriginal('verification_status') !== 'draft' || array_diff(array_keys($evidence->getDirty()), $allowed)) {
                throw new LogicException('Billing evidence is append-only. Create a new version and obtain independent verification.');
            }
        });
        static::deleting(fn () => throw new LogicException('Billing evidence versions are retained.'));
    }
}
