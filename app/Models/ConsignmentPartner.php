<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentPartner extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = ['name', 'contact_person', 'phone', 'location', 'note'];

    /**
     * @return HasMany<Consignment, $this>
     */
    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class, 'partner_id');
    }

    /**
     * @return HasMany<ConsignmentSale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(ConsignmentSale::class, 'partner_id');
    }

    /**
     * @return HasMany<ConsignmentAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(ConsignmentAdjustment::class, 'partner_id');
    }

    /**
     * @return HasMany<ConsignmentSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(ConsignmentSettlement::class, 'partner_id');
    }
}
