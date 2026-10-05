<?php

namespace App\Analytics;

use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where the stock is: what is on hand, what it is worth, and how it is moving.
 *
 * Valuation is current stock at its current cost price. That is the honest
 * figure for a balance-sheet view — it answers "what is this worth if we sell
 * it tomorrow" — as opposed to a P&L view, which uses historical cost and lives
 * in ProfitAnalytics. Mixing the two is the usual way inventory reports end up
 * disagreeing with the profit report.
 *
 * Turnover is deliberately not computed. Movements carry no before/after
 * balance, so a true turnover ratio would require assuming an opening stock
 * that the database does not actually hold.
 */
class InventoryAnalytics extends AnalyticsQuery
{
    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsFilters $filters): array
    {
        return [
            'summary' => $this->summary(),
            'byCategory' => $this->byCategory(),
            'valuation' => $this->valuation(),
            'expiry' => $this->expiryProfile(),
            'movementTypes' => $this->movementTypes($filters),
            'movementReasons' => $this->movementReasons($filters),
            'topMovements' => $this->largestMovements($filters),
            'slowMoving' => $this->slowMoving($filters),
            'activity' => $this->activityTrend($filters),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function summary(): array
    {
        $products = Product::query()
            ->select(['id', 'stock', 'cost_price', 'price', 'expiry_date', 'name'])
            ->get();

        $units = 0.0;
        $atCost = 0.0;
        $atRetail = 0.0;

        foreach ($products as $product) {
            $stock = (float) $product->stock;
            $units += $stock;
            $atCost += $stock * (float) $product->cost_price;
            $atRetail += $stock * (float) $product->price;
        }

        return [
            'products' => $products->count(),
            'stocked_products' => $products->filter(fn (Product $product): bool => (float) $product->stock > 0)->count(),
            'stock_units' => $units,
            'stock_value_cost' => $atCost,
            'stock_value_retail' => $atRetail,
            'potential_margin' => $atRetail > 0 ? (($atRetail - $atCost) / $atRetail) * 100 : 0.0,
            'low_stock' => Product::lowStock()->count(),
            'out_of_stock' => Product::where('stock', '<=', 0)->count(),
            'expiring_soon' => Product::expiringSoon()->count(),
            'expired' => Product::whereNotNull('expiry_date')->whereDate('expiry_date', '<', now())->count(),
        ];
    }

    /**
     * @return array<int, array{label: string, units: float, value: float, products: int}>
     */
    public function byCategory(): array
    {
        $rows = Product::query()
            ->groupBy('category')
            ->select([
                'category',
                DB::raw('COUNT(*) AS products'),
                DB::raw('SUM(stock) AS units'),
                DB::raw('SUM(stock * COALESCE(NULLIF(cost_price, 0), price)) AS value'),
            ])
            ->get();

        return $rows->map(fn ($row) => [
            'label' => ucfirst((string) $row->category),
            'products' => (int) $row->products,
            'units' => (float) $row->units,
            'value' => (float) $row->value,
        ])->sortByDesc('value')->values()->all();
    }

    /**
     * Retail value against cost value per category, as the chart needs both.
     *
     * @return array<int, array{label: string, cost_value: float, retail_value: float, units: float}>
     */
    public function valuation(): array
    {
        $rows = Product::query()
            ->groupBy('category')
            ->select([
                'category',
                DB::raw('SUM(stock * COALESCE(NULLIF(cost_price, 0), price)) AS cost_value'),
                DB::raw('SUM(stock * COALESCE(NULLIF(price, 0), cost_price)) AS retail_value'),
                DB::raw('SUM(stock) AS units'),
            ])
            ->get();

        return $rows->map(fn ($row) => [
            'label' => ucfirst((string) $row->category),
            'cost_value' => (float) $row->cost_value,
            'retail_value' => (float) $row->retail_value,
            'units' => (float) $row->units,
        ])->sortByDesc('cost_value')->values()->all();
    }

    /**
     * Expiry buckets, plus anything already expired, so unusable stock cannot
     * hide inside a healthy-looking total.
     *
     * @return array<int, array{label: string, products: int, units: float, value: float}>
     */
    public function expiryProfile(): array
    {
        $buckets = [
            ['label' => 'Expired', 'from' => null, 'to' => now()->subDay()],
            ['label' => 'This month', 'from' => now()->startOfMonth(), 'to' => now()->endOfMonth()],
            ['label' => 'Next 3 months', 'from' => now()->addMonths(1)->startOfMonth(), 'to' => now()->addMonths(3)->endOfDay()],
            ['label' => '4 to 6 months', 'from' => now()->addMonths(4)->startOfMonth(), 'to' => now()->addMonths(6)->endOfDay()],
            ['label' => 'Beyond 6 months', 'from' => now()->addMonths(7)->startOfMonth(), 'to' => null],
        ];

        $out = [];

        foreach ($buckets as $bucket) {
            $query = Product::query()
                ->select([
                    DB::raw('COUNT(*) AS products'),
                    DB::raw('SUM(stock) AS units'),
                    DB::raw('SUM(stock * COALESCE(NULLIF(cost_price, 0), price)) AS value'),
                ]);

            if ($bucket['from'] === null) {
                $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $bucket['to']);
            } else {
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', $bucket['from'])
                    ->when($bucket['to'] !== null, fn ($query) => $query->whereDate('expiry_date', '<=', $bucket['to']));
            }

            $row = $query->first();

            $out[] = [
                'label' => $bucket['label'],
                'products' => (int) ($row->products ?? 0),
                'units' => (float) ($row->units ?? 0),
                'value' => (float) ($row->value ?? 0),
            ];
        }

        $out[] = [
            'label' => 'No expiry date',
            'products' => Product::whereNull('expiry_date')->count(),
            'units' => (float) Product::whereNull('expiry_date')->sum('products.stock'),
            'value' => (float) Product::whereNull('expiry_date')->sum(DB::raw('stock * COALESCE(NULLIF(cost_price, 0), price)')),
        ];

        return $out;
    }

    /**
     * Units in and out within the period.
     *
     * @return array<int, array{label: string, value: float}>
     */
    public function movementTypes(AnalyticsFilters $filters): array
    {
        $rows = $this->movements($filters)
            ->groupBy('stock_movements.type')
            ->select([
                'stock_movements.type',
                DB::raw('SUM(stock_movements.quantity) AS quantity'),
            ])
            ->get()
            ->keyBy('type');

        return [
            ['label' => 'Stock in', 'value' => (float) ($rows['in']->quantity ?? 0)],
            ['label' => 'Stock out', 'value' => (float) ($rows['out']->quantity ?? 0)],
        ];
    }

    /**
     * Units moved per reason, which is where shrinkage becomes visible.
     *
     * @return array<int, array{label: string, value: float, movements: int}>
     */
    public function movementReasons(AnalyticsFilters $filters): array
    {
        $rows = $this->movements($filters)
            ->groupBy('stock_movements.reason')
            ->select([
                'stock_movements.reason',
                DB::raw('SUM(stock_movements.quantity) AS quantity'),
                DB::raw('COUNT(*) AS movements'),
            ])
            ->get()
            ->map(fn ($row) => [
                'label' => (string) ($row->reason ?? 'Unspecified'),
                'value' => (float) $row->quantity,
                'movements' => (int) $row->movements,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return $rows;
    }

    /**
     * The biggest individual movements in the period.
     *
     * @return array<int, array{id: int, product: string, type: string, reason: string, quantity: float, date: string, supplier: string|null}>
     */
    public function largestMovements(AnalyticsFilters $filters, int $limit = 10): array
    {
        return $this->movements($filters)
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_movements.supplier_id')
            ->orderByDesc('stock_movements.quantity')
            ->limit($limit)
            ->select([
                'stock_movements.id',
                'products.name as product_name',
                'stock_movements.type',
                'stock_movements.reason',
                'stock_movements.quantity',
                'stock_movements.created_at',
                'suppliers.name as supplier_name',
            ])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'product' => (string) $row->product_name,
                'type' => (string) $row->type,
                'reason' => (string) ($row->reason ?? 'Unspecified'),
                'quantity' => (float) $row->quantity,
                'date' => (string) $row->created_at,
                'supplier' => $row->supplier_name === null ? null : (string) $row->supplier_name,
            ])
            ->all();
    }

    /**
     * Stock that is not moving. A long window is required before calling
     * something slow, so a product that simply has not had a chance to sell yet
     * is not flagged.
     *
     * @return array<int, array{id: int, name: string, category: string, units: float, value: float, last_sold: string|null}>
     */
    public function slowMoving(AnalyticsFilters $filters, int $limit = 10): array
    {
        $lastSold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.created_at', '<=', $filters->period->to)
            ->groupBy('sale_items.product_id')
            ->select('sale_items.product_id', DB::raw('MAX(sales.created_at) AS last_sold'))
            ->pluck('last_sold', 'product_id');

        $soldInPeriod = array_keys($this->netQuantityByProduct($filters));

        $rows = Product::query()
            ->where('stock', '>', 0)
            ->whereNotIn('id', $soldInPeriod)
            ->when($filters->category !== null, fn ($query) => $query->where('category', $filters->category))
            ->orderByDesc(DB::raw('stock * COALESCE(NULLIF(cost_price, 0), price)'))
            ->limit($limit)
            ->get(['id', 'name', 'category', 'stock', 'cost_price', 'price']);

        return $rows->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'category' => (string) $product->category,
            'units' => (float) $product->stock,
            'value' => (float) $product->stock * (float) $product->cost_price,
            'last_sold' => $lastSold[$product->id] ?? null,
        ])->all();
    }

    /**
     * Movement volume per bucket across the period.
     *
     * @return array<int, array{label: string, in: float, out: float}>
     */
    public function activityTrend(AnalyticsFilters $filters): array
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? match ($filters->period->grouping()) {
                'week' => "DATE(stock_movements.created_at, '-' || ((CAST(STRFTIME('%w', stock_movements.created_at) AS INTEGER) + 6) % 7) || ' days')",
                'month' => "STRFTIME('%Y-%m-01', stock_movements.created_at)",
                default => 'DATE(stock_movements.created_at)',
            }
        : match ($filters->period->grouping()) {
            'week' => 'DATE(DATE_SUB(stock_movements.created_at, INTERVAL WEEKDAY(stock_movements.created_at) DAY))',
            'month' => "DATE_FORMAT(stock_movements.created_at, '%Y-%m-01')",
            default => 'DATE(stock_movements.created_at)',
        };

        // Grouped by type as well as bucket: grouping only by the bucket lets the
        // database hand back one arbitrary type per bucket, which would report a
        // day's receipts as if they were its issues.
        $rows = $this->movements($filters)
            ->groupByRaw($expression)
            ->groupBy('stock_movements.type')
            ->select([
                DB::raw("{$expression} AS bucket"),
                'stock_movements.type',
                DB::raw('SUM(stock_movements.quantity) AS quantity'),
            ])
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $bucket = (string) $row->bucket;
            $totals[$bucket] ??= ['in' => 0.0, 'out' => 0.0];
            $totals[$bucket][$row->type === 'in' ? 'in' : 'out'] += (float) $row->quantity;
        }

        $buckets = $filters->period->grouping() === 'day'
            ? $this->movementBuckets($filters)
            : $this->periodBuckets($filters);

        return array_map(function (string $bucket) use ($totals, $filters): array {
            $row = $totals[$bucket] ?? null;

            return [
                'label' => $this->bucketLabel($bucket, $filters->period->grouping()),
                'in' => (float) ($row['in'] ?? 0),
                'out' => (float) ($row['out'] ?? 0),
            ];
        }, $buckets);
    }

    /**
     * @return array<int, string>
     */
    private function movementBuckets(AnalyticsFilters $filters): array
    {
        return $this->periodBuckets($filters);
    }

    /**
     * @return Builder<Builder>
     */
    private function movements(AnalyticsFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereBetween('stock_movements.created_at', [$filters->period->from, $filters->period->to])
            ->when($filters->category !== null, fn ($query) => $query->where('products.category', $filters->category))
            ->when($filters->productId !== null, fn ($query) => $query->where('stock_movements.product_id', $filters->productId));
    }
}
