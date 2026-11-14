<?php

namespace App\Models;

use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property float|null $floor_area_m2
 * @property string|null $operating_hours
 * @property string|null $notes
 */
#[Fillable(['name', 'code', 'floor_area_m2', 'operating_hours', 'notes'])]
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_building');
    }

    /**
     * @return HasMany<Meter, $this>
     */
    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    /** @return HasManyThrough<MonthlyRecord, Meter, $this> */
    public function monthlyRecords(): HasManyThrough
    {
        return $this->hasManyThrough(MonthlyRecord::class, Meter::class);
    }
}
