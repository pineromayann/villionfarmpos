<?php

namespace App\Analytics;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Product-level performance: which lines carry the business and which are dead
 * weight on the shelf.
 *
 * Everything is expressed per product so a manager can act on it — reorder,
 * reprice, or delist. Revenue and units come from owned sales net of returns;
 * cost comes from CostBasis so margin reflects what the goods actually cost.
 */
class ProductPerformanceAnalytics extends AnalyticsQuery
{
    public function __construct(private readonly CostBasis $costs = new CostBasis)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsFilters $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'rows' => $this->rows($filters),
            'byCategory' => $this->byCategory($filters),
            'matrix' => $this->salesVersusStock($filters),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function summary(AnalyticsFilters $filters): array
    {
        $rows = $this->rows($filters);

        $sold = array_values(array_filter($rows, fn (array $row): bool => $row['units_sold'] > 0));
        $unsold = array_values(array_filter($rows, fn (array $row): bool => $row['units_sold'] <= 0));

        // As on the gross profit page: revenue without a cost cannot be split
        // into profit, so uncosted lines are surfaced rather than booked whole.
        $costed = array_values(array_filter($rows, fn (array $row): bool => $row['gross_profit'] !== null));
        $uncosted = array_values(array_filter($rows, fn (array $row): bool => $row['gross_profit'] === null));

        $revenue = array_sum(array_column($costed, 'revenue'));
        $profit = array_sum(array_column($costed, 'gross_profit'));

        return [
            'products' => count($rows),
            'sold_products' => count($sold),
            'unsold_products' => count($unsold),
            'uncosted_products' => count($uncosted),
            'excluded_revenue' => array_sum(array_column($uncosted, 'revenue')),
            'revenue' => $revenue,
            'gross_profit' => $profit,
            'margin' => $revenue > 0 ? ($profit / $revenue) * 100 : 0.0,
            'average_units' => count($sold) > 0
                ? array_sum(array_column($sold, 'units_sold')) / count($sold)
                : 0.0,
            'dead_stock_value' => array_sum(array_map(
                fn (array $row): float => $row['units_sold'] > 0 ? 0.0 : $row['stock_value'],
                $rows,
            )),
        ];
    }

    /**
     * A row per product, ready to render or export.
     *
     * The product universe is everything sold in the period *plus* everything
     * still holding stock, filtered the same way. Restricting it to sold products
     * would quietly drop the products this report exists to find: stock that never
     * sold has no sale line at all, so it would vanish from both the table and the
     * dead-stock value.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(AnalyticsFilters $filters): array
    {
        $net = $this->netQuantityByProduct($filters);

        $stocked = Product::query()
            ->where('stock', '>', 0)
            ->when($filters->category !== null, fn ($query) => $query->where('category', $filters->category))
            ->when($filters->productId !== null, fn ($query) => $query->where('id', $filters->productId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $productIds = array_values(array_unique([...array_keys($net), ...$stocked]));

        if ($productIds === []) {
            return [];
        }

        $this->costs->prime($productIds);

        $soldValue = $this->ownedSaleItems($filters)
            ->whereIn('sale_items.product_id', $productIds)
            ->groupBy('sale_items.product_id')
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.line_total) AS revenue'))
            ->pluck('revenue', 'product_id');

        $returnedValue = $this->ownedRefunds($filters)
            ->whereIn('refunds.product_id', $productIds)
            ->groupBy('refunds.product_id')
            ->select('refunds.product_id', DB::raw('SUM(refunds.line_total) AS revenue'))
            ->pluck('revenue', 'product_id');

        $movementIn = DB::table('stock_movements')
            ->whereIn('product_id', $productIds)
            ->where('type', 'in')
            ->whereBetween('created_at', [$filters->period->from, $filters->period->to])
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(quantity) AS quantity'))
            ->pluck('quantity', 'product_id');

        $movementOut = DB::table('stock_movements')
            ->whereIn('product_id', $productIds)
            ->where('type', 'out')
            ->whereBetween('created_at', [$filters->period->from, $filters->period->to])
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(quantity) AS quantity'))
            ->pluck('quantity', 'product_id');

        $products = Product::with('baseUnit')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($productIds as $productId) {
            $product = $products[$productId] ?? null;

            if ($product === null) {
                continue;
            }

            $units = $net[$productId] ?? 0.0;
            $revenue = (float) ($soldValue[$productId] ?? 0) - (float) ($returnedValue[$productId] ?? 0);
            $costPerBase = $this->costs->costPerBase($product);
            $cost = $costPerBase === null ? null : $costPerBase * $units;

            $rows[] = [
                'id' => $productId,
                'name' => $product->name,
                'category' => (string) $product->category,
                'unit' => $product->unit,
                'units_sold' => $units,
                'stock_on_hand' => (float) $product->stock,
                'stock_in' => (float) ($movementIn[$productId] ?? 0),
                'stock_out' => (float) ($movementOut[$productId] ?? 0),
                'revenue' => $revenue,
                'avg_price' => $units > 0 ? $revenue / $units : 0.0,
                'cost' => $cost,
                'uses_fallback_cost' => $costPerBase !== null && $this->costs->isUsingFallback($productId),
                'gross_profit' => $cost === null ? null : $revenue - $cost,
                'margin' => ($cost === null || $revenue == 0.0) ? null : (($revenue - $cost) / $revenue) * 100,
                'stock_value' => (float) $product->stock * (float) $product->cost_price,
                'is_low_stock' => $product->isLowStock(),
                'is_expiring' => $product->isExpiringSoon(),
                'expiry_date' => $product->expiry_date?->toDateString(),
            ];
        }

        return $rows;
    }

    /**
     * Columns the product table can be ordered by.
     *
     * A fixed list rather than an arbitrary column name: the rows are assembled in
     * PHP, so whatever arrives in the query string is used as an array key and
     * must never reach a query or an undefined-index error.
     */
    public const SORTABLE = [
        'name',
        'category',
        'units_sold',
        'stock_on_hand',
        'revenue',
        'cost',
        'gross_profit',
        'margin',
        'stock_value',
    ];

    /**
     * Rows ordered by the requested column, with the product name as the
     * tie-breaker so equal values keep a stable order across pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sortedRows(AnalyticsFilters $filters, string $sort = 'revenue', string $direction = 'desc'): array
    {
        $rows = $this->rows($filters);
        $column = in_array($sort, self::SORTABLE, true) ? $sort : 'revenue';
        $descending = strtolower($direction) !== 'asc';

        usort($rows, function (array $a, array $b) use ($column, $descending): int {
            // An unknown cost or a zero-revenue product sorts as zero rather than
            // jumping to the top of a descending list.
            $left = $a[$column] ?? null;
            $right = $b[$column] ?? null;

            if (is_string($left) || is_string($right)) {
                $comparison = strcasecmp((string) $left, (string) $right);
            } else {
                $comparison = (float) $left <=> (float) $right;
            }

            if ($comparison !== 0) {
                return $descending ? -$comparison : $comparison;
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }

    /**
     * Revenue and profit per category, for the chart.
     *
     * @return array<int, array{label: string, revenue: float, profit: float}>
     */
    public function byCategory(AnalyticsFilters $filters): array
    {
        $grouped = [];

        foreach ($this->rows($filters) as $row) {
            if ($row['gross_profit'] === null) {
                continue;
            }

            $key = $row['category'];
            $grouped[$key] ??= ['revenue' => 0.0, 'profit' => 0.0];
            $grouped[$key]['revenue'] += $row['revenue'];
            $grouped[$key]['profit'] += $row['gross_profit'];
        }

        $rows = [];

        foreach ($grouped as $category => $totals) {
            $rows[] = [
                'label' => ucfirst((string) $category),
                'revenue' => $totals['revenue'],
                'profit' => $totals['profit'],
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    /**
     * Sold units against stock still on hand, which is how a manager sees
     * cover: high sold with low stock means reorder, low sold with high stock
     * means dead capital.
     *
     * @return array<int, array{id: int, name: string, units: float, stock: float, cover: float|null}>
     */
    public function salesVersusStock(AnalyticsFilters $filters, int $limit = 12): array
    {
        $rows = array_values(array_filter(
            $this->rows($filters),
            fn (array $row): bool => $row['units_sold'] > 0 || $row['stock_on_hand'] > 0,
        ));

        usort($rows, fn (array $a, array $b) => $b['units_sold'] <=> $a['units_sold']);

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'units' => $row['units_sold'],
            'stock' => $row['stock_on_hand'],
            'cover' => $row['units_sold'] > 0 ? $row['stock_on_hand'] / $row['units_sold'] : null,
        ], array_slice($rows, 0, $limit));
    }
}
