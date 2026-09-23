<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = ['product_id', 'unit_id', 'conversion_to_base', 'is_base'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_to_base' => 'decimal:4',
            'is_base' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
