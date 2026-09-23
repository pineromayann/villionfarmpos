<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = ['unit_type_id', 'name', 'abbreviation'];

    /**
     * @return BelongsTo<UnitType, $this>
     */
    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    /**
     * @return HasMany<ProductUnit, $this>
     */
    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
