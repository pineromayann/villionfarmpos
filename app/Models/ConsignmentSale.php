<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentSale extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'partner_id',
        'sale_id',
        'sale_item_id',
        'customer_id',
        'product_id',
        'quantity',
        'unit_id',
        'unit_price',
        'line_total',
        'unit_cost',
        'payable_amount',
        'refunded_quantity',
        'refunded_line_total',
        'refunded_payable',
        'sold_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'refunded_quantity' => 'decimal:2',
            'refunded_line_total' => 'decimal:2',
            'refunded_payable' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ConsignmentPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(ConsignmentPartner::class, 'partner_id');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<SaleItem, $this>
     */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /**
     * Units still sold (excludes customer returns) in base units.
     */
    public function remainingQuantity(): float
    {
        return (float) $this->quantity - (float) $this->refunded_quantity;
    }

    /**
     * Retail value still on sale after customer returns.
     */
    public function netLineTotal(): float
    {
        return (float) $this->line_total - (float) $this->refunded_line_total;
    }

    /**
     * Amount still owed to the partner after customer returns.
     */
    public function netPayable(): float
    {
        return (float) $this->payable_amount - (float) $this->refunded_payable;
    }

    /**
     * Farm store commission earned on this line: retail less the partner payable.
     */
    public function commissionEarned(): float
    {
        return $this->netLineTotal() - $this->netPayable();
    }

    public function hasRefund(): bool
    {
        return (float) $this->refunded_quantity > 0;
    }

    public function isFullyRefunded(): bool
    {
        return (float) $this->refunded_quantity >= (float) $this->quantity;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
