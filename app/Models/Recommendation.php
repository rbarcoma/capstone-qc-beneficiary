<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array<string, mixed> $evidence
 * @property array<string, mixed>|null $subject_snapshot
 */
#[Fillable(['forecast_id', 'rule_code', 'subject_key', 'subject_snapshot', 'policy_fingerprint', 'source_fingerprint', 'title', 'message', 'evidence', 'priority', 'contains_cost_data', 'evaluation_status', 'decision', 'version', 'decided_by', 'decided_at'])]
class Recommendation extends Model
{
    protected $attributes = ['subject_key' => 'building'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'array', 'subject_snapshot' => 'array', 'contains_cost_data' => 'boolean', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Forecast, $this> */
    public function forecast(): BelongsTo
    {
        return $this->belongsTo(Forecast::class);
    }

    /** @return HasMany<RecommendationDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(RecommendationDecision::class);
    }

    /** @return HasOne<Action, $this> */
    public function action(): HasOne
    {
        return $this->hasOne(Action::class);
    }
}
