<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentSettlement extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = ['partner_id', 'amount', 'settled_at', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_at' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
