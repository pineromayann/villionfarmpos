<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

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
        'base_unit_id',
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

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return HasMany<ProductUnit, $this>
     */
    public function sellingUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->with('unit');
    }

    /**
     * Display name kept for backward compatibility: the base unit abbreviation.
     */
    protected function unit(): Attribute
    {
        return Attribute::get(fn () => $this->baseUnit?->abbreviation);
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
     * The default selling unit for this product: its base unit.
     */
    public function defaultSellingUnit(): ?ProductUnit
    {
        return $this->sellingUnits->firstWhere('is_base', true)
            ?? $this->sellingUnits->first();
    }

    /**
     * Convert a quantity expressed in one of the product's selling units into base units.
     */
    public function convertToBase(float $quantity, Unit $unit): float
    {
        $productUnit = $this->sellingUnits->firstWhere('unit_id', $unit->id);

        if ($productUnit === null) {
            throw new RuntimeException("The unit \"{$unit->abbreviation}\" is not a selling unit for {$this->name}.");
        }

        if ($this->baseUnit !== null && $unit->unit_type_id !== $this->baseUnit->unit_type_id) {
            throw new RuntimeException("Cannot convert \"{$unit->abbreviation}\" to base unit \"{$this->baseUnit->abbreviation}\".");
        }

        return $quantity * (float) $productUnit->conversion_to_base;
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
