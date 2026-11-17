<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Storage only: a restriction cannot grant access; an absent row inherits the
 * role baseline. No authorization or module-catalogue policy is implemented here.
 *
 * @property int $id
 * @property int $user_id
 * @property string $module_key
 * @property User $user
 */
#[Fillable(['user_id', 'module_key'])]
class UserModuleRestriction extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
