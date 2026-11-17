<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property User|null $actor
 * @property int|null $subject_user_id
 * @property User|null $subjectUser
 * @property Carbon|null $created_at
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
#[Fillable(['actor_id', 'subject_user_id', 'event', 'monthly_record_id', 'forecast_id', 'before', 'after', 'reason'])]
class AuditLog extends Model
{
    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Optional live lookup; subject_user_id survives account deletion. Historical
     * names/roles, when recorded, belong in the retained event snapshots.
     *
     * @return BelongsTo<User, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'subject_user_id' => 'integer'];
    }
}
