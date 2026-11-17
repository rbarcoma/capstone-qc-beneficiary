<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $due_date
 * @property User|null $owner
 * @property Carbon|null $completed_at
 * @property-read bool $has_acceptance Query-computed withExists alias on the tracker list.
 */
#[Fillable(['recommendation_id', 'owner_id', 'title', 'due_date', 'status', 'outcome', 'version', 'completed_at'])]
class Action extends Model
{
    public const STATUSES = ['open', 'completed'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Recommendation, $this> */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /** @return HasMany<ActionUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(ActionUpdate::class);
    }

    /** @param Builder<Action> $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereHas('recommendation', function ($recommendation) use ($user) {
            $recommendation->where('decision', 'accepted')->whereHas('forecast.building', fn ($building) => $building->when(
                $user->role->isBuildingRestricted(), fn ($restricted) => $restricted->whereIn('id', $user->allowedBuildingIds() ?? []),
            ));
            if ($user->role->isBuildingRestricted()) {
                $recommendation->where('contains_cost_data', false)->where('rule_code', '!=', 'R1');
            }
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->only(['id', 'recommendation_id', 'owner_id', 'title', 'due_date', 'status', 'outcome', 'version', 'completed_at']);
    }
}
