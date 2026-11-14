<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $month
 * @property string $kwh
 * @property string|null $cost_php
 * @property string|null $billed_demand_kw
 * @property string|null $power_factor
 */
#[Fillable(['meter_id', 'month', 'kwh', 'billed_demand_kw', 'cost_php', 'power_factor', 'notes', 'verification_status', 'source', 'uploaded_by', 'verified_by', 'verified_at', 'last_edited_by', 'version'])]
class MonthlyRecord extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d', 'kwh' => 'decimal:3', 'billed_demand_kw' => 'decimal:3',
            'cost_php' => 'decimal:2', 'power_factor' => 'decimal:4', 'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Meter, $this> */
    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    /** @return HasMany<MonthlyRecordRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(MonthlyRecordRevision::class);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->only(['meter_id', 'month', 'kwh', 'billed_demand_kw', 'cost_php', 'power_factor', 'notes', 'verification_status', 'uploaded_by', 'last_edited_by', 'verified_by', 'verified_at', 'version']);
    }

    /** @return array<string, mixed> */
    public function reviewData(User $user): array
    {
        return [
            'id' => $this->id, 'month' => $this->month->format('Y-m-d'), 'kwh' => $this->kwh,
            'billedDemandKw' => $this->billed_demand_kw, 'costPhp' => $this->cost_php,
            'powerFactor' => $this->power_factor, 'notes' => $this->notes,
            'verificationStatus' => $this->verification_status, 'meterLabel' => $this->meter?->maskedRef(),
            'version' => $this->version, 'canVerify' => $this->verification_status === 'draft' && $user->can('verify', $this),
            'canCorrect' => $user->can('correct', $this),
        ];
    }

    /** @param Builder<MonthlyRecord> $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereHas('meter.building', fn (Builder $building) => $building->when(
            $user->role->isBuildingRestricted(),
            fn (Builder $restricted) => $restricted->whereIn('id', $user->allowedBuildingIds() ?? []),
        ));
    }

    /** @param Builder<MonthlyRecord> $query */
    public function scopeVerified(Builder $query): void
    {
        $query->where('verification_status', 'verified');
    }
}
