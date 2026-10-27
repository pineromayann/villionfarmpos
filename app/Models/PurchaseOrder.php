<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    const STATUSES = ['ordered', 'received', 'cancelled'];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'supplier_id',
        'status',
        'order_date',
        'expected_date',
        'total',
        'received_total',
        'note',
        'received_note',
        'received_at',
        'parent_order_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'total' => 'decimal:2',
            'received_total' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->with('product', 'unit');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The order this one balances out, when it was raised for a short delivery.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    /**
     * Balance orders raised for the shortfall of this order.
     *
     * @return HasMany<PurchaseOrder, $this>
     */
    public function balanceOrders(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    /**
     * Stock movements written when this order was received.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'ref_id')->where('ref_type', 'procurement');
    }

    public function isOrdered(): bool
    {
        return $this->status === 'ordered';
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Value actually received into stock, falling back to the ordered value
     * for orders received before partial receiving was available.
     */
    public function receivedTotal(): float
    {
        return (float) ($this->received_total ?? $this->total);
    }

    /**
     * Value of the lines that never arrived.
     */
    public function shortfallTotal(): float
    {
        return max(0.0, (float) $this->total - $this->receivedTotal());
    }

    /**
     * True when the delivery was short of what was ordered.
     */
    public function isPartial(): bool
    {
        return $this->isReceived() && $this->shortfallTotal() > 0;
    }

    public function itemCount(): int
    {
        return $this->items->count();
    }
}
