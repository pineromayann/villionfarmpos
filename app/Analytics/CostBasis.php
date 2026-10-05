<?php

namespace App\Analytics;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Answers "what did this product cost, at the time we sold it?" for analytics.
 *
 * The database keeps two cost sources, and neither one alone is good enough:
 *
 * - `products.cost_price` is what the product costs *today*. Using it to value
 *   last month's sales silently rewrites history every time a price is edited,
 *   so a report of margins drifts away from the margins that were actually
 *   earned.
 * - `stock_movements.unit_cost` is written when stock is received and is the
 *   real, dated cost of that stock. But not every movement carries one: sales
 *   and returns deliberately do not, and an item stocked before cost tracking
 *   existed has none at all.
 *
 * So this builds a weighted average cost per base unit from every stock-in
 * movement that recorded a cost, and falls back to the product's current cost
 * price only for products with no costing history whatsoever. Products taking
 * the fallback are tracked so reports can label themselves honestly rather
 * than implying a precision they do not have.
 *
 * This average covers all recorded receipts, not just those on hand at the time
 * of a sale. It is therefore a lifetime average for the product, which is a
 * reasonable proxy but not true FIFO or a point-in-time cost — the schema keeps
 * no cost layers to draw one from. It should be read as "what this product has
 * historically cost us", and it is described that way in the UI.
 *
 * Quantities are already stored in base units everywhere in this application,
 * so no quantity conversion happens here; only the unit cost needs converting,
 * because it is recorded per the selling unit the movement was raised in.
 */
class CostBasis
{
    /**
     * Weighted average cost per base unit, keyed by product id.
     *
     * @var array<int, float>
     */
    private array $averageCost = [];

    /**
     * Products with no recorded stock-in cost, which fall back to `cost_price`.
     *
     * @var array<int, true>
     */
    private array $fallback = [];

    /**
     * Cost per base unit for a product, or null when it cannot be known.
     *
     * Callers must treat null as "unknown cost" rather than zero: reporting a
     * missing cost as a free product inflates margin just as badly as pricing
     * it wrongly, so the figure is allowed to stay unknown and be flagged.
     */
    public function costPerBase(Product $product): ?float
    {
        $cost = $this->averageCostFor($product->id);

        if ($cost !== null) {
            return $cost;
        }

        $fallback = (float) $product->cost_price;

        return $fallback > 0 ? $fallback : null;
    }

    /**
     * Cost per base unit for a product id, falling back to a supplied cost price.
     */
    public function costPerBaseById(int $productId, float $fallbackCostPrice = 0.0): ?float
    {
        $cost = $this->averageCostFor($productId);

        if ($cost !== null) {
            return $cost;
        }

        return $fallbackCostPrice > 0 ? $fallbackCostPrice : null;
    }

    /**
     * Whether the product has no costing history and today's cost price is
     * standing in for it. Reports use this to caveat their own numbers.
     */
    public function isUsingFallback(int $productId): bool
    {
        return ! isset($this->averageCost[$productId]);
    }

    /**
     * How many of the primed products are relying on the fallback.
     */
    public function fallbackCount(): int
    {
        return count($this->fallback);
    }

    private function averageCostFor(int $productId): ?float
    {
        $this->prime([$productId]);

        return $this->averageCost[$productId] ?? null;
    }

    /**
     * @param  array<int, int>  $productIds
     */
    public function prime(array $productIds): void
    {
        $missing = array_values(array_filter(
            array_unique(array_map('intval', $productIds)),
            fn (int $productId): bool => ! array_key_exists($productId, $this->averageCost)
                && ! array_key_exists($productId, $this->fallback),
        ));

        if ($missing === []) {
            return;
        }

        /** @var array<int, array<int, float>> $conversions product_id => unit_id => conversion_to_base */
        $conversions = DB::table('product_units')
            ->whereIn('product_id', $missing)
            ->get(['product_id', 'unit_id', 'conversion_to_base'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->mapWithKeys(
                fn ($row) => [(int) $row->unit_id => (float) $row->conversion_to_base],
            )->all())
            ->all();

        $movements = DB::table('stock_movements')
            ->whereIn('product_id', $missing)
            ->where('type', 'in')
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->get(['product_id', 'quantity', 'unit_id', 'unit_cost']);

        $totals = [];

        foreach ($movements as $movement) {
            $productId = (int) $movement->product_id;
            $baseQuantity = (float) $movement->quantity;

            if ($baseQuantity <= 0) {
                continue;
            }

            $conversion = $conversions[$productId][(int) $movement->unit_id] ?? 1.0;
            $costPerBase = (float) $movement->unit_cost / ($conversion > 0 ? $conversion : 1.0);

            $totals[$productId]['quantity'] = ($totals[$productId]['quantity'] ?? 0) + $baseQuantity;
            $totals[$productId]['value'] = ($totals[$productId]['value'] ?? 0) + ($baseQuantity * $costPerBase);
        }

        foreach ($missing as $productId) {
            if (isset($totals[$productId]) && $totals[$productId]['quantity'] > 0) {
                $this->averageCost[$productId] = $totals[$productId]['value'] / $totals[$productId]['quantity'];
            } else {
                $this->fallback[$productId] = true;
            }
        }
    }
}
