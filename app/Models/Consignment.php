<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consignment extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = ['partner_id', 'received_at', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'date',
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
     * @return HasMany<ConsignmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ConsignmentItem::class)->with('product', 'unit');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
