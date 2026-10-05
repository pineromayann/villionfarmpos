<?php

namespace App\Analytics;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Gross profit: what the shop actually keeps from trading owned stock.
 *
 * The cost side uses CostBasis, which prefers the dated cost recorded against
 * stock received at the time. Consigned lines are excluded entirely — the store
 * earns a commission on those, not a margin, and ConsignmentAnalytics reports
 * them. Mixing the two would produce a "profit" number that nobody could
 * reconcile against the bank.
 *
 * Products whose cost cannot be established at all are reported separately
 * rather than valued at zero, because a missing cost silently read as zero is
 * the single easiest way to make a margin report wrong.
 */
class ProfitAnalytics extends AnalyticsQuery
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
            'trend' => $this->trend($filters),
            'byProduct' => $this->byProduct($filters),
            'byCategory' => $this->byCategory($filters),
            'pricePoints' => $this->pricePoints($filters),
            'comparison' => $this->comparison($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(AnalyticsFilters $filters): array
    {
        $lines = $this->profitLines($filters);

        // Only costed lines can produce a trustworthy profit figure. Folding an
        // uncosted line's revenue into the total while adding nothing for its
        // cost would book the whole sale value as profit and flatter the margin,
        // so uncosted lines are reported separately rather than estimated at zero.
        //
        // Returns are settled per product for the same reason: a return against
        // an uncosted product is already excluded with that product's revenue,
        // so deducting it a second time here would understate costed trading.
        $costed = array_values(array_filter($lines, fn (array $line): bool => $line['cost'] !== null));
        $uncosted = array_values(array_filter($lines, fn (array $line): bool => $line['cost'] === null));

        $grossRevenue = array_sum(array_column($costed, 'sold'));
        $returnedValue = array_sum(array_column($costed, 'returned'));
        $returnedQuantity = array_sum(array_column($costed, 'returned_units'));
        $revenue = $grossRevenue - $returnedValue;
        $cost = array_sum(array_column($costed, 'cost'));
        $grossProfit = $revenue - $cost;

        return [
            'revenue' => $revenue,
            'cogs' => $cost,
            'gross_profit' => $grossProfit,
            'margin' => $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0.0,
            'markup' => $cost > 0 ? ($grossProfit / $cost) * 100 : 0.0,
            'returns' => $returnedValue,
            'returned_units' => $returnedQuantity,
            'excluded_revenue' => array_sum(array_column($uncosted, 'revenue')),
            'uncosted_products' => count($uncosted),
        ];
    }

    /**
     * One row per product sold in the period, with revenue and cost resolved.
     *
     * `sold`, `returned` and `revenue` are kept apart because the summary has to
     * settle returns per product: a return against an uncosted product must be
     * excluded with that product, not deducted from the costed total.
     *
     * @return array<int, array{id: int, name: string, category: string, units: float, sold: float, returned: float, returned_units: float, revenue: float, cost: float|null, gross_profit: float|null, margin: float|null}>
     */
    private function profitLines(AnalyticsFilters $filters): array
    {
        $net = $this->netQuantityByProduct($filters);

        if ($net === []) {
            return [];
        }

        $productIds = array_keys($net);
        $this->costs->prime($productIds);

        $sold = $this->ownedSaleItems($filters)
            ->whereIn('sale_items.product_id', $productIds)
            ->groupBy('sale_items.product_id')
            ->select([
                'sale_items.product_id',
                DB::raw('SUM(sale_items.line_total) AS revenue'),
                DB::raw('SUM(sale_items.quantity) AS quantity'),
            ])
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->product_id => [
                'revenue' => (float) $row->revenue,
                'quantity' => (float) $row->quantity,
            ]]);

        $returned = $this->ownedRefunds($filters)
            ->whereIn('refunds.product_id', $productIds)
            ->groupBy('refunds.product_id')
            ->select([
                'refunds.product_id',
                DB::raw('SUM(refunds.line_total) AS revenue'),
                DB::raw('SUM(refunds.quantity) AS quantity'),
            ])
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->product_id => [
                'revenue' => (float) $row->revenue,
                'quantity' => (float) $row->quantity,
            ]]);

        $products = Product::whereIn('id', $productIds)->get(['id', 'name', 'category', 'cost_price'])->keyBy('id');

        $lines = [];

        foreach ($productIds as $productId) {
            $product = $products[$productId] ?? null;

            if ($product === null) {
                continue;
            }

            $soldValue = (float) ($sold[$productId]['revenue'] ?? 0);
            $returnedValue = (float) ($returned[$productId]['revenue'] ?? 0);
            $revenue = $soldValue - $returnedValue;
            $units = $net[$productId];
            $costPerBase = $this->costs->costPerBase($product);

            $lines[] = [
                'id' => $productId,
                'name' => $product->name,
                'category' => (string) $product->category,
                'units' => $units,
                'sold' => $soldValue,
                'returned' => $returnedValue,
                'returned_units' => (float) ($returned[$productId]['quantity'] ?? 0),
                'revenue' => $revenue,
                'cost' => $costPerBase === null ? null : $costPerBase * $units,
                'cost_per_unit' => $costPerBase,
                'uses_fallback_cost' => $costPerBase !== null && $this->costs->isUsingFallback($productId),
                'gross_profit' => $costPerBase === null ? null : $revenue - ($costPerBase * $units),
                'margin' => ($costPerBase === null || $revenue == 0.0)
                    ? null
                    : (($revenue - ($costPerBase * $units)) / $revenue) * 100,
            ];
        }

        return $lines;
    }

    /**
     * @return array<int, array{label: string, value: float, cost: float, gross_profit: float, margin: float}>
     */
    public function byProduct(AnalyticsFilters $filters, int $limit = 15): array
    {
        $lines = array_filter($this->profitLines($filters), fn (array $line): bool => $line['cost'] !== null);

        usort($lines, fn (array $a, array $b) => $b['gross_profit'] <=> $a['gross_profit']);

        return array_map(fn (array $line) => [
            'label' => $line['name'],
            'value' => $line['revenue'],
            'cost' => $line['cost'],
            'gross_profit' => $line['gross_profit'],
            'margin' => $line['margin'],
        ], array_slice($lines, 0, $limit));
    }

    /**
     * @return array<int, array{label: string, value: float, cost: float, gross_profit: float, margin: float}>
     */
    public function byCategory(AnalyticsFilters $filters): array
    {
        $grouped = [];

        foreach ($this->profitLines($filters) as $line) {
            if ($line['cost'] === null) {
                continue;
            }

            $key = $line['category'];
            $grouped[$key] ??= ['revenue' => 0.0, 'cost' => 0.0];
            $grouped[$key]['revenue'] += $line['revenue'];
            $grouped[$key]['cost'] += $line['cost'];
        }

        $rows = [];

        foreach ($grouped as $category => $totals) {
            $profit = $totals['revenue'] - $totals['cost'];

            $rows[] = [
                'label' => ucfirst((string) $category),
                'value' => $totals['revenue'],
                'cost' => $totals['cost'],
                'gross_profit' => $profit,
                'margin' => $totals['revenue'] > 0 ? ($profit / $totals['revenue']) * 100 : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['gross_profit'] <=> $a['gross_profit']);

        return $rows;
    }

    /**
     * Where the selling price actually sits against cost, which is the quickest
     * way to spot a product priced under water.
     *
     * @return array<int, array{id: int, name: string, price: float, cost: float|null, margin: float|null}>
     */
    public function pricePoints(AnalyticsFilters $filters, int $limit = 15): array
    {
        $lines = $this->profitLines($filters);
        $productIds = array_column($lines, 'id');

        if ($productIds === []) {
            return [];
        }

        $products = Product::whereIn('id', $productIds)->get(['id', 'name', 'price', 'cost_price'])->keyBy('id');

        $rows = [];

        foreach ($lines as $line) {
            $product = $products[$line['id']] ?? null;

            if ($product === null) {
                continue;
            }

            $price = (float) $product->price;
            $cost = $this->costs->costPerBase($product);

            $rows[] = [
                'id' => $line['id'],
                'name' => $product->name,
                'price' => $price,
                'cost' => $cost,
                'margin' => ($cost === null || $price <= 0) ? null : (($price - $cost) / $price) * 100,
                'sold' => $line['units'],
            ];
        }

        usort($rows, fn (array $a, array $b) => ($a['margin'] ?? 999) <=> ($b['margin'] ?? 999));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Revenue and gross profit per bucket, so margin drift is visible over time.
     *
     * @return array<int, array{label: string, revenue: float, profit: float}>
     */
    public function trend(AnalyticsFilters $filters): array
    {
        $lines = $this->profitLines($filters);

        if ($lines === []) {
            return array_map(
                fn (string $bucket) => [
                    'label' => $this->bucketLabel($bucket, $filters->period->grouping()),
                    'revenue' => 0.0,
                    'profit' => 0.0,
                ],
                $this->periodBuckets($filters),
            );
        }

        $buckets = $this->profitByBucket($filters, $lines);

        return array_map(function (string $bucket) use ($buckets, $filters): array {
            $row = $buckets[$bucket] ?? null;

            return [
                'label' => $this->bucketLabel($bucket, $filters->period->grouping()),
                'revenue' => (float) ($row['revenue'] ?? 0),
                'profit' => (float) ($row['profit'] ?? 0),
            ];
        }, $this->periodBuckets($filters));
    }

    /**
     * Revenue and profit grouped by sales date bucket.
     *
     * Cost is applied per product rather than per row so a product sold on
     * several days lands wholly in the day it was sold, with returns netted
     * into the refund's own date.
     *
     * @param  array<int, array{id: int, cost_per_unit: float|null}>  $lines
     * @return array<string, array{revenue: float, profit: float}>
     */
    private function profitByBucket(AnalyticsFilters $filters, array $lines): array
    {
        $costPerUnit = [];

        foreach ($lines as $line) {
            $costPerUnit[$line['id']] = $line['cost_per_unit'];
        }

        $expression = $this->salesDateBucket($filters);

        // Both the bucket and the product have to be grouped: grouping only by
        // the bucket leaves SQLite and MySQL free to hand back an arbitrary
        // product_id for the whole bucket, which would apply one product's cost
        // to every other product sold on the same day.
        $sold = $this->ownedSaleItems($filters)
            ->groupByRaw($expression)
            ->groupBy('sale_items.product_id')
            ->select([
                DB::raw("{$expression} AS bucket"),
                'sale_items.product_id',
                DB::raw('SUM(sale_items.quantity) AS units'),
                DB::raw('SUM(sale_items.line_total) AS revenue'),
            ])
            ->get();

        $refundExpression = str_replace('sales.created_at', 'refunds.created_at', $expression);

        $returned = $this->ownedRefunds($filters)
            ->groupByRaw($refundExpression)
            ->groupBy('refunds.product_id')
            ->select([
                DB::raw("{$refundExpression} AS bucket"),
                'refunds.product_id',
                DB::raw('SUM(refunds.quantity) AS units'),
                DB::raw('SUM(refunds.line_total) AS revenue'),
            ])
            ->get();

        $totals = [];

        foreach ($sold as $row) {
            $unitCost = $costPerUnit[(int) $row->product_id] ?? null;
            $bucket = (string) $row->bucket;

            // An uncosted product is left out of the trend entirely, matching the
            // summary: counting its revenue without its cost would draw a profit
            // curve the headline figures do not support.
            if ($unitCost === null) {
                continue;
            }

            $totals[$bucket] ??= ['revenue' => 0.0, 'cost' => 0.0];
            $totals[$bucket]['cost'] += $unitCost * (float) $row->units;
            $totals[$bucket]['revenue'] += (float) $row->revenue;
        }

        foreach ($returned as $row) {
            $unitCost = $costPerUnit[(int) $row->product_id] ?? null;
            $bucket = (string) $row->bucket;

            if ($unitCost === null) {
                continue;
            }

            $totals[$bucket] ??= ['revenue' => 0.0, 'cost' => 0.0];
            $totals[$bucket]['cost'] -= $unitCost * (float) $row->units;
            $totals[$bucket]['revenue'] -= (float) $row->revenue;
        }

        return array_map(
            fn (array $total) => [
                'revenue' => $total['revenue'],
                'profit' => $total['revenue'] - $total['cost'],
            ],
            $totals,
        );
    }

    /**
     * @return array<string, float|int|null>
     */
    public function comparison(AnalyticsFilters $filters): array
    {
        $days = max(1, $filters->period->days());

        $previous = $filters->withPeriod(
            AnalyticsPeriod::between(
                $filters->period->from->copy()->subDays($days),
                $filters->period->from->copy()->subSecond(),
                $filters->period->preset,
            )
        );

        $earliest = DB::table('sales')->min('created_at');

        if ($earliest === null) {
            return ['gross_profit_change' => null, 'margin_change' => null];
        }

        $current = $this->summary($filters);
        $prior = $this->summary($previous);

        $marginChange = ((float) $current['margin'] - (float) $prior['margin']) ?: null;

        return [
            'gross_profit_change' => SalesAnalytics::percentChange(
                (float) $prior['gross_profit'],
                (float) $current['gross_profit'],
            ),
            'margin_change' => $marginChange,
        ];
    }
}
