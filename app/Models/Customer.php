<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = ['name', 'farm_name', 'phone', 'location', 'crop', 'hectares', 'notes'];

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * What this customer has spent across every sale.
     *
     * Uses the preloaded sum when the caller has eager loaded it, so a list
     * of customers does not run a query per row.
     */
    public function lifetimeSpend(): float
    {
        if ($this->lifetime_spend !== null) {
            return (float) $this->lifetime_spend;
        }

        return (float) $this->sales()->sum('total');
    }
}
