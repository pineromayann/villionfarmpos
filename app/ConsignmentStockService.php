<?php

namespace App;

use App\Models\ConsignmentAdjustment;
use App\Models\ConsignmentItem;
use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\ConsignmentSettlement;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Facades\DB;

class ConsignmentStockService
{
    /**
     * Consigned on-hand stock for a product and partner, in base units.
     */
    public static function remainingBase(Product $product, ?ConsignmentPartner $partner = null): float
    {
        return static::receivedBase($product, $partner)
            - static::soldBase($product, $partner)
            - static::adjustedBase($product, $partner);
    }

    /**
     * Consigned stock received for a product (optionally limited to one partner), in base units.
     */
    public static function receivedBase(Product $product, ?ConsignmentPartner $partner = null): float
    {
        $conversions = static::conversions($product);
        $rows = DB::table('consignment_items')
            ->join('consignments', 'consignments.id', '=', 'consignment_items.consignment_id')
            ->where('consignment_items.product_id', $product->id)
            ->when($partner !== null, fn ($query) => $query->where('consignments.partner_id', $partner->id))
            ->select(['consignment_items.quantity', 'consignment_items.unit_id'])
            ->get();

        return $rows->sum(fn ($row) => static::toBase((float) $row->quantity, $row->unit_id, $conversions));
    }

    /**
     * Consigned stock sold for a product (optionally limited to one partner), in base units.
     */
    public static function soldBase(Product $product, ?ConsignmentPartner $partner = null): float
    {
        $conversions = static::conversions($product);

        return ConsignmentSale::query()
            ->where('product_id', $product->id)
            ->when($partner !== null, fn ($query) => $query->where('partner_id', $partner->id))
            ->select(['quantity', 'unit_id'])
            ->get()
            ->sum(fn ($sale) => static::toBase((float) $sale->quantity, $sale->unit_id, $conversions));
    }

    /**
     * Consigned stock written off or returned for a product (optionally limited to one partner), in base units.
     */
    public static function adjustedBase(Product $product, ?ConsignmentPartner $partner = null): float
    {
        $adjustments = ConsignmentAdjustment::query()
            ->where('product_id', $product->id)
            ->when($partner !== null, fn ($query) => $query->where('partner_id', $partner->id))
            ->pluck('quantity');

        return $adjustments->sum(fn ($quantity) => (float) $quantity);
    }

    /**
     * Weighted average consignment price per base unit across everything a partner
     * has delivered for a product. Used to price new sales and damage write-offs.
     */
    public static function averageCostPerBase(Product $product, ConsignmentPartner $partner): float
    {
        $conversions = static::conversions($product);
        $rows = DB::table('consignment_items')
            ->join('consignments', 'consignments.id', '=', 'consignment_items.consignment_id')
            ->where('consignment_items.product_id', $product->id)
            ->where('consignments.partner_id', $partner->id)
            ->select(['consignment_items.quantity', 'consignment_items.unit_id', 'consignment_items.unit_cost'])
            ->get();

        $totalBase = 0;
        $totalValue = 0;

        foreach ($rows as $row) {
            $conversion = $conversions[(int) $row->unit_id] ?? 1;
            $baseQty = (float) $row->quantity * $conversion;
            $totalBase += $baseQty;
            $totalValue += $baseQty * ((float) $row->unit_cost / $conversion);
        }

        return $totalBase > 0 ? $totalValue / $totalBase : 0.0;
    }

    /**
     * Value of the consigned stock currently held for a partner, priced at the
     * weighted average consignment cost of each product received from them.
     */
    public static function onHandValue(ConsignmentPartner $partner): float
    {
        $productIds = ConsignmentItem::query()
            ->whereHas('consignment', fn ($query) => $query->where('partner_id', $partner->id))
            ->distinct()
            ->pluck('product_id');

        $total = 0;

        foreach ($productIds as $productId) {
            $product = Product::find($productId);

            if ($product === null) {
                continue;
            }

            $total += static::remainingBase($product, $partner) * static::averageCostPerBase($product, $partner);
        }

        return $total;
    }

    /**
     * Amount the store still owes a partner (or, if negative, the partner owes
     * the store): payable on sold units + damage/loss write-offs - cash settled.
     */
    public static function balanceDue(ConsignmentPartner $partner): float
    {
        $soldPayable = (float) ConsignmentSale::where('partner_id', $partner->id)->sum('payable_amount');
        $adjustments = (float) ConsignmentAdjustment::where('partner_id', $partner->id)->sum('value');
        $settled = (float) ConsignmentSettlement::where('partner_id', $partner->id)->sum('amount');

        return $soldPayable + $adjustments - $settled;
    }

    /**
     * Combined balance owed to all partners.
     */
    public static function totalBalanceDue(): float
    {
        $soldPayable = (float) ConsignmentSale::sum('payable_amount');
        $adjustments = (float) ConsignmentAdjustment::sum('value');
        $settled = (float) ConsignmentSettlement::sum('amount');

        return $soldPayable + $adjustments - $settled;
    }

    /**
     * @return array<int, float> unit_id => conversion_to_base for a product
     */
    private static function conversions(Product $product): array
    {
        return ProductUnit::where('product_id', $product->id)
            ->pluck('conversion_to_base', 'unit_id')
            ->mapWithKeys(fn (string $conversion, int $unitId) => [$unitId => (float) $conversion])
            ->all();
    }

    private static function toBase(float $quantity, ?int $unitId, array $conversions): float
    {
        return $unitId !== null ? $quantity * ($conversions[$unitId] ?? 1) : $quantity;
    }
}
