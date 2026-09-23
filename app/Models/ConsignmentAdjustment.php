<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentAdjustment extends Model
{
    use HasFactory;

    const REASONS = ['return', 'damaged', 'expired', 'lost', 'other'];

    /**
     * @var array<int, string>
     */
    protected $fillable = ['partner_id', 'product_id', 'quantity', 'reason', 'value', 'adjusted_at', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'value' => 'decimal:2',
            'adjusted_at' => 'date',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
