<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    const PAYMENT_METHODS = ['cash', 'card', 'mobile_money'];

    const RECEIPT_PREFIX = 'ORN-';

    const RECEIPT_PADDING = 6;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'receipt_number',
        'subtotal',
        'discount',
        'total',
        'payment_method',
        'amount_paid',
        'change_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundTotal(): float
    {
        return (float) $this->refunds()->sum('line_total');
    }

    public function refundedQuantityFor(SaleItem $item): float
    {
        return (float) $this->refunds()->where('sale_item_id', $item->id)->sum('quantity');
    }

    public function itemCount(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    /**
     * The order receipt number quoted on the sale.
     */
    public function receiptNumber(): string
    {
        return $this->receipt_number ?? $this->deriveReceiptNumber();
    }

    /**
     * Build the receipt number for this sale from its own identifier.
     */
    public function deriveReceiptNumber(): string
    {
        return self::RECEIPT_PREFIX.str_pad((string) $this->id, self::RECEIPT_PADDING, '0', STR_PAD_LEFT);
    }
}
