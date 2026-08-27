<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'farm_name', 'phone', 'location', 'crop', 'hectares', 'notes'])]
class Customer extends Model
{
    use HasFactory;

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function lifetimeSpend(): float
    {
        return (float) $this->sales()->sum('total');
    }
}
