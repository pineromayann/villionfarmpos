<?php

namespace App\Procurement;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Which products should be bought next, and how much.
 *
 * A product is suggested when its stock has fallen to its reorder level. The
 * suggested quantity covers the reorder point plus roughly a month of recent
 * demand, so the shop does not run out again the week after the delivery. A
 * product with no sales history falls back to a plain top-up to its reorder
 * point, because there is no demand signal to size the order against.
 *
 * Everything is expressed in base units: that is the unit `products.stock` is
 * kept in, the unit `sale_items.quantity` is recorded in, and the unit the
 * suggested quantity is ordered in.
 */
class SuggestionService
{
    /**
     * How far back demand is measured. A quarter of trading gives a stable
     * monthly average without going stale on a seasonal product.
     */
    public const DEMAND_WINDOW_DAYS = 90;

    /**
     * How many suggestions the procurement panel shows inline before the rest
     * are moved into a scrollable dialog. A shop with a full catalogue can have
     * a hundred products under their reorder point at once, and the inline
     * list is only useful if the emptiest shelves are at the top of it.
     */
    public const PREVIEW_SIZE = 5;

    /**
     * Products to buy next.
     *
     * Products already sitting on an open purchase order are left out: they
     * are already covered, and suggesting them again would push the buyer to
     * order the same thing twice.
     *
     * @return array<int, array{
     *     product_id: int,
     *     name: string,
     *     category: string,
     *     unit: string,
     *     stock: float,
     *     reorder_level: float,
     *     quantity: float,
     *     estimated_cost: float|null,
     *     unit_id: int|null,
     *     unit_cost: float|null,
     *     orderable: bool
     * }>
     */
    public function suggestions(): array
    {
        $products = Product::with(['baseUnit', 'sellingUnits.unit'])
            ->lowStock()
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->map(fn (Product $product): int => (int) $product->id)->all();

        $onOrder = $this->productsOnOpenOrders($productIds);
        $demand = $this->monthlyDemand($productIds);

        $suggestions = [];

        foreach ($products as $product) {
            if (in_array((int) $product->id, $onOrder, true)) {
                continue;
            }

            $level = $product->reorderLevel();
            $stock = (float) $product->stock;
            $monthly = $demand[(int) $product->id] ?? 0.0;

            $quantity = $monthly > 0
                ? max(0.0, $level + $monthly - $stock)
                : max(0.0, $level - $stock);

            if ($quantity <= 0) {
                continue;
            }

            $sellingUnit = $product->defaultSellingUnit();
            $cost = $product->cost_price !== null ? (float) $product->cost_price : null;

            $suggestions[] = [
                'product_id' => (int) $product->id,
                'name' => $product->name,
                'category' => (string) $product->category,
                'unit' => $product->unit ?? '',
                'stock' => $stock,
                'reorder_level' => $level,
                'quantity' => round($quantity, 2),
                'estimated_cost' => $cost !== null ? round($quantity * $cost, 2) : null,
                'unit_id' => $sellingUnit?->unit_id,
                'unit_cost' => $cost,
                'orderable' => $sellingUnit !== null,
            ];
        }

        usort($suggestions, function (array $a, array $b) use ($demand): int {
            $coverA = $this->coverRatio($a);
            $coverB = $this->coverRatio($b);

            if ($coverA !== $coverB) {
                return $coverA <=> $coverB;
            }

            // Equally empty shelves are ordered by how fast they sell: the
            // line that moves fastest is the one that will run out soonest.
            $demandA = $demand[$a['product_id']] ?? 0.0;
            $demandB = $demand[$b['product_id']] ?? 0.0;

            if ($demandA !== $demandB) {
                return $demandB <=> $demandA;
            }

            return ($b['estimated_cost'] ?? 0) <=> ($a['estimated_cost'] ?? 0);
        });

        return $suggestions;
    }

    /**
     * How many low-stock products are already covered by an open order.
     *
     * @return int
     */
    public function coveredByOpenOrders(): int
    {
        $productIds = Product::lowStock()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($productIds === []) {
            return 0;
        }

        return count($this->productsOnOpenOrders($productIds));
    }

    /**
     * Product ids that already appear on a purchase order that has not been
     * received or cancelled.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    private function productsOnOpenOrders(array $productIds): array
    {
        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.status', 'ordered')
            ->whereIn('purchase_order_items.product_id', $productIds)
            ->distinct()
            ->pluck('purchase_order_items.product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Net base units sold per product per month, over the demand window.
     *
     * Refunds are netted off the sales they cancel, and consigned lines are
     * excluded: neither belongs to stock this shop owns and has to replace.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    private function monthlyDemand(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $from = now()->subDays(self::DEMAND_WINDOW_DAYS)->startOfDay();

        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('consignment_sales', 'consignment_sales.sale_item_id', '=', 'sale_items.id')
            ->whereNull('consignment_sales.id')
            ->whereIn('sale_items.product_id', $productIds)
            ->where('sales.created_at', '>=', $from)
            ->groupBy('sale_items.product_id')
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) AS quantity'))
            ->pluck('quantity', 'product_id');

        $returned = DB::table('refunds')
            ->join('sales', 'sales.id', '=', 'refunds.sale_id')
            ->leftJoin('consignment_sales', 'consignment_sales.sale_item_id', '=', 'refunds.sale_item_id')
            ->whereNull('consignment_sales.id')
            ->whereIn('refunds.product_id', $productIds)
            ->where('refunds.created_at', '>=', $from)
            ->groupBy('refunds.product_id')
            ->select('refunds.product_id', DB::raw('SUM(refunds.quantity) AS quantity'))
            ->pluck('quantity', 'product_id');

        $demand = [];

        foreach ($sold->keys() as $productId) {
            $net = (float) $sold[$productId] - (float) ($returned[$productId] ?? 0);

            $demand[(int) $productId] = max(0.0, $net) / (self::DEMAND_WINDOW_DAYS / 30);
        }

        return $demand;
    }

    /**
     * How much of the reorder level the stock still covers.
     *
     * The lower the ratio the emptier the shelf, so the emptier shelves are
     * suggested first.
     *
     * @param  array{stock: float, reorder_level: float}  $suggestion
     */
    private function coverRatio(array $suggestion): float
    {
        $level = $suggestion['reorder_level'];

        if ($level <= 0) {
            return 0.0;
        }

        return $suggestion['stock'] / $level;
    }
}
