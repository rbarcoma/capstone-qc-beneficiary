<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['recommendation_id', 'actor_id', 'from_state', 'to_state', 'note', 'recommendation_version', 'source_snapshot'])]
class RecommendationDecision extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['source_snapshot' => 'array'];
    }
}
