<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    const CATEGORIES = ['foliar', 'herbicide', 'insecticide', 'molluscicide'];

    const LOW_STOCK_THRESHOLD = 10;

    const EXPIRING_SOON_MONTHS = 6;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'category',
        'active_ingredient',
        'batch_number',
        'expiry_date',
        'price',
        'cost_price',
        'dealers_price_cod',
        'terms_30_days',
        'stock',
        'unit',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'dealers_price_cod' => 'decimal:2',
            'terms_30_days' => 'decimal:2',
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

    public function salePrice(): float
    {
        return (float) ($this->dealers_price_cod ?? $this->terms_30_days ?? $this->cost_price ?? $this->price);
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

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
