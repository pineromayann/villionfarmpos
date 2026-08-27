<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'active_ingredient', 'batch_number', 'expiry_date', 'price', 'stock', 'unit'])]
class Product extends Model
{
    use HasFactory;

    const LOW_STOCK_THRESHOLD = 10;

    const EXPIRING_SOON_MONTHS = 6;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'price' => 'decimal:2',
            'stock' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function isLowStock(): bool
    {
        return $this->stock <= self::LOW_STOCK_THRESHOLD;
    }

    public function isExpiringSoon(): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->lessThanOrEqualTo(now()->addMonths(self::EXPIRING_SOON_MONTHS));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('stock', '<=', self::LOW_STOCK_THRESHOLD);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeExpiringSoon(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addMonths(self::EXPIRING_SOON_MONTHS));
    }
}
