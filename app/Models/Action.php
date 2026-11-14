<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $due_date
 * @property User|null $owner
 */
#[Fillable(['recommendation_id', 'owner_id', 'title', 'due_date', 'status', 'outcome'])]
class Action extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
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
}
