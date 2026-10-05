<?php

namespace App\Analytics;

use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales performance: what was sold, to whom, and how the shop is trending.
 *
 * Revenue is measured on owned stock only. Consigned lines bring cash through
 * the till but the goods belong to a partner, so folding them into "revenue"
 * would overstate the shop's own trading and double-count against the
 * consignment report. Returns are subtracted so a returned product never
 * inflates a total.
 */
class SalesAnalytics extends AnalyticsQuery
{
    /**
     * Headline figures plus the breakdowns and trend the sales page renders.
     *
     * @return array<string, mixed>
     */
    public function report(AnalyticsFilters $filters): array
    {
        $summary = $this->summary($filters);

        return [
            'summary' => $summary,
            'trend' => $this->trend($filters),
            'byDay' => $this->byDayOfWeek($filters),
            'byPayment' => $this->byPaymentMethod($filters),
            'byCategory' => $this->byCategory($filters),
            'byProduct' => $this->topProducts($filters),
            'byCustomer' => $this->topCustomers($filters),
            'byHour' => $this->byHourOfDay($filters),
            'comparison' => $this->comparison($filters),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function summary(AnalyticsFilters $filters): array
    {
        $sales = $this->salesInPeriod($filters);
        $transactions = (clone $sales)->count();
        $grossBeforeDiscount = (float) (clone $sales)->sum('sales.subtotal');
        $discounts = (float) (clone $sales)->sum('sales.discount');
        $take = (float) (clone $sales)->sum('sales.total');

        $returnsValue = (float) $this->ownedRefunds($filters)->sum('refunds.line_total');
        $returnsUnits = (float) $this->ownedRefunds($filters)->sum('refunds.quantity');

        $unitsSold = (float) $this->ownedSaleItems($filters)->sum('sale_items.quantity');
        $netUnits = $unitsSold - $returnsUnits;

        // Line-level owned revenue, not the sale header. A header total mixes in
        // consigned lines and is struck after the sale-level discount, neither
        // of which belongs in the owned-stock report the rest of this class
        // measures. Kept alongside the header figures so both can be shown.
        $ownedRevenue = (float) $this->ownedSaleItems($filters)->sum('sale_items.line_total');
        $netSales = $ownedRevenue - $returnsValue;

        return [
            'transactions' => $transactions,
            'gross_sales' => $grossBeforeDiscount,
            'discounts' => $discounts,
            'sales' => $take,
            'has_consigned_lines' => (bool) $this->hasConsignedLines($filters),
            'owned_revenue' => $ownedRevenue,
            'returns' => $returnsValue,
            'return_units' => $returnsUnits,
            'net_sales' => $netSales,
            'units_sold' => $unitsSold,
            'net_units_sold' => $netUnits,
            'average_transaction' => $transactions > 0 ? $take / $transactions : 0.0,
            'average_unit_price' => $netUnits > 0 ? $netSales / $netUnits : 0.0,
            'return_rate' => $ownedRevenue > 0 ? ($returnsValue / $ownedRevenue) * 100 : 0.0,
        ];
    }

    /**
     * Whether any sale in the period carried consigned lines.
     *
     * The header total includes them while every breakdown here excludes them, so
     * the views need to know when the two figures legitimately disagree.
     */
    protected function hasConsignedLines(AnalyticsFilters $filters): bool
    {
        // Consigned lines live on the sale header, so they are matched through the
        // same sales the rest of the page counts. Every narrowing filter has to
        // narrow them too, otherwise a filtered report still claims consigned
        // activity from sales the filter excluded. The period is taken from
        // `sales.created_at` rather than `sold_at` so the flag describes the same
        // set of sales the headline totals are built from.
        return DB::table('consignment_sales')
            ->join('sales', 'sales.id', '=', 'consignment_sales.sale_id')
            ->join('products', 'products.id', '=', 'consignment_sales.product_id')
            ->whereBetween('sales.created_at', [$filters->period->from, $filters->period->to])
            ->when($filters->category !== null, fn (Builder $query) => $query->where('products.category', $filters->category))
            ->when($filters->productId !== null, fn (Builder $query) => $query->where('consignment_sales.product_id', $filters->productId))
            ->when($filters->customerId !== null, fn (Builder $query) => $query->where('sales.customer_id', $filters->customerId))
            ->when($filters->paymentMethod !== null, fn (Builder $query) => $query->where('sales.payment_method', $filters->paymentMethod))
            ->exists();
    }

    /**
     * Revenue trend, with every bucket in the period present even when empty.
     *
     * @return array<int, array{label: string, value: float, transactions: int}>
     */
    public function trend(AnalyticsFilters $filters): array
    {
        $bucket = $this->salesDateBucket($filters);
        $grouping = $filters->period->grouping();

        $rows = $this->ownedSaleItems($filters)
            ->groupByRaw($bucket)
            ->select([
                DB::raw("{$bucket} AS bucket"),
                DB::raw('SUM(sale_items.line_total) AS value'),
                DB::raw('COUNT(DISTINCT sales.id) AS transactions'),
            ])
            ->get()
            ->keyBy('bucket');

        $returns = $this->ownedRefunds($filters)
            ->groupByRaw(str_replace('sales.created_at', 'refunds.created_at', $bucket))
            ->select([
                DB::raw(str_replace('sales.created_at', 'refunds.created_at', $bucket).' AS bucket'),
                DB::raw('SUM(refunds.line_total) AS value'),
            ])
            ->get()
            ->keyBy('bucket');

        return array_map(function (string $day) use ($rows, $returns, $grouping): array {
            $sold = (float) ($rows[$day]->value ?? 0);
            $returned = (float) ($returns[$day]->value ?? 0);

            return [
                'label' => $this->bucketLabel($day, $grouping),
                'value' => $sold - $returned,
                'transactions' => (int) ($rows[$day]->transactions ?? 0),
            ];
        }, $this->periodBuckets($filters));
    }

    /**
     * @return array<int, array{label: string, value: float, transactions: int}>
     */
    public function byDayOfWeek(AnalyticsFilters $filters): array
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "STRFTIME('%w', sales.created_at)"
            : "DATE_FORMAT(sales.created_at, '%w')";

        $labels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        $rows = $this->ownedSaleItems($filters)
            ->groupByRaw($expression)
            ->select([
                DB::raw("{$expression} AS bucket"),
                DB::raw('SUM(sale_items.line_total) AS value'),
                DB::raw('COUNT(DISTINCT sales.id) AS transactions'),
            ])
            ->get()
            ->keyBy(fn ($row) => (string) (int) $row->bucket);

        $returns = $this->ownedRefunds($filters)
            ->groupByRaw(DB::connection()->getDriverName() === 'sqlite'
                ? "STRFTIME('%w', refunds.created_at)"
                : "DATE_FORMAT(refunds.created_at, '%w')")
            ->select([
                DB::raw(DB::connection()->getDriverName() === 'sqlite'
                    ? "STRFTIME('%w', refunds.created_at) AS bucket"
                    : "DATE_FORMAT(refunds.created_at, '%w') AS bucket"),
                DB::raw('SUM(refunds.line_total) AS value'),
            ])
            ->get()
            ->keyBy(fn ($row) => (string) (int) $row->bucket);

        $out = [];

        foreach ([1, 2, 3, 4, 5, 6, 0] as $index) {
            $day = (string) $index;

            $out[] = [
                'label' => $labels[$index],
                'value' => (float) ($rows[$day]->value ?? 0) - (float) ($returns[$day]->value ?? 0),
                'transactions' => (int) ($rows[$day]->transactions ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{label: string, value: float, transactions: int}>
     */
    public function byPaymentMethod(AnalyticsFilters $filters): array
    {
        $rows = $this->ownedSaleItems($filters)
            ->groupBy('sales.payment_method')
            ->select([
                'sales.payment_method',
                DB::raw('SUM(sale_items.line_total) AS value'),
                DB::raw('COUNT(DISTINCT sales.id) AS transactions'),
            ])
            ->get();

        return $rows->map(fn ($row) => [
            'label' => str_replace('_', ' ', ucfirst((string) $row->payment_method)),
            'value' => (float) $row->value,
            'transactions' => (int) $row->transactions,
        ])->sortByDesc('value')->values()->all();
    }

    /**
     * @return array<int, array{label: string, value: float, units: float}>
     */
    public function byCategory(AnalyticsFilters $filters): array
    {
        $rows = $this->ownedSaleItems($filters)
            ->groupBy('products.category')
            ->select([
                'products.category',
                DB::raw('SUM(sale_items.line_total) AS value'),
                DB::raw('SUM(sale_items.quantity) AS units'),
            ])
            ->get();

        return $rows->map(fn ($row) => [
            'label' => ucfirst((string) $row->category),
            'value' => (float) $row->value,
            'units' => (float) $row->units,
        ])->sortByDesc('value')->values()->all();
    }

    /**
     * Best selling products by net revenue, excluding consigned lines.
     *
     * @return array<int, array{id: int, name: string, category: string, units: float, revenue: float}>
     */
    public function topProducts(AnalyticsFilters $filters, int $limit = 10): array
    {
        $net = $this->netQuantityByProduct($filters);

        if ($net === []) {
            return [];
        }

        $sold = $this->ownedSaleItems($filters)
            ->whereIn('sale_items.product_id', array_keys($net))
            ->groupBy('sale_items.product_id')
            ->select([
                'sale_items.product_id',
                DB::raw('SUM(sale_items.line_total) AS revenue'),
            ])
            ->pluck('revenue', 'product_id');

        $returnedValue = $this->ownedRefunds($filters)
            ->whereIn('refunds.product_id', array_keys($net))
            ->groupBy('refunds.product_id')
            ->select('refunds.product_id', DB::raw('SUM(refunds.line_total) AS revenue'))
            ->pluck('revenue', 'product_id');

        $names = Product::whereIn('id', array_keys($net))
            ->get(['id', 'name', 'category'])
            ->keyBy('id');

        $rows = [];

        foreach ($net as $productId => $units) {
            $product = $names[$productId] ?? null;

            if ($product === null) {
                continue;
            }

            $rows[] = [
                'id' => (int) $productId,
                'name' => $product->name,
                'category' => (string) $product->category,
                'units' => $units,
                'revenue' => (float) ($sold[$productId] ?? 0) - (float) ($returnedValue[$productId] ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Top customers by net spend. Walk-in sales group under a placeholder row
     * rather than being dropped, because they are real money.
     *
     * @return array<int, array{name: string, transactions: int, spend: float, is_walk_in: bool}>
     */
    public function topCustomers(AnalyticsFilters $filters, int $limit = 10): array
    {
        $rows = $this->ownedSaleItems($filters)
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupBy('sales.customer_id', 'customers.name')
            ->select([
                'sales.customer_id',
                'customers.name',
                DB::raw('SUM(sale_items.line_total) AS spend'),
                DB::raw('COUNT(DISTINCT sales.id) AS transactions'),
            ])
            ->get();

        $returned = $this->ownedRefunds($filters)
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupBy('sales.customer_id')
            ->select('sales.customer_id', DB::raw('SUM(refunds.line_total) AS spend'))
            ->pluck('spend', 'customer_id');

        $mapped = $rows->map(fn ($row) => [
            'name' => $row->customer_id === null ? 'Walk-in customer' : (string) $row->name,
            'transactions' => (int) $row->transactions,
            'spend' => (float) $row->spend - (float) ($returned[$row->customer_id] ?? 0),
            'is_walk_in' => $row->customer_id === null,
        ])->sortByDesc('spend')->values()->all();

        return array_slice($mapped, 0, $limit);
    }

    /**
     * Sales by hour of day, to show when the shop is actually busy.
     *
     * @return array<int, array{hour: int, label: string, value: float, transactions: int}>
     */
    public function byHourOfDay(AnalyticsFilters $filters): array
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(STRFTIME('%H', sales.created_at) AS INTEGER)"
            : 'HOUR(sales.created_at)';

        $hours = $this->ownedSaleItems($filters)
            ->groupByRaw($expression)
            ->select([
                DB::raw("{$expression} AS bucket"),
                DB::raw('SUM(sale_items.line_total) AS value'),
                DB::raw('COUNT(DISTINCT sales.id) AS transactions'),
            ])
            ->get()
            ->keyBy(fn ($row) => (string) (int) $row->bucket);

        $returned = $this->ownedRefunds($filters)
            ->groupByRaw(DB::connection()->getDriverName() === 'sqlite'
                ? "CAST(STRFTIME('%H', refunds.created_at) AS INTEGER)"
                : 'HOUR(refunds.created_at)')
            ->select([
                DB::raw((DB::connection()->getDriverName() === 'sqlite'
                    ? "CAST(STRFTIME('%H', refunds.created_at) AS INTEGER)"
                    : 'HOUR(refunds.created_at)').' AS bucket'),
                DB::raw('SUM(refunds.line_total) AS value'),
            ])
            ->get()
            ->keyBy(fn ($row) => (string) (int) $row->bucket);

        $out = [];

        foreach (range(6, 21) as $hour) {
            $key = (string) $hour;

            $out[] = [
                'hour' => $hour,
                'label' => sprintf('%02d:00', $hour),
                'value' => (float) ($hours[$key]->value ?? 0) - (float) ($returned[$key]->value ?? 0),
                'transactions' => (int) ($hours[$key]->transactions ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The same window immediately before this one, for a period-on-period
     * read. Null when the previous window falls outside recorded history.
     *
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

        $earliest = (string) DB::table('sales')->min('created_at');

        if ($earliest === '' || $earliest === null) {
            return ['net_sales_change' => null, 'transactions_change' => null, 'units_change' => null];
        }

        $current = $this->summary($filters);
        $prior = $this->summary($previous);

        return [
            'net_sales_change' => self::percentChange((float) $prior['net_sales'], (float) $current['net_sales']),
            'transactions_change' => self::percentChange((float) $prior['transactions'], (float) $current['transactions']),
            'units_change' => self::percentChange((float) $prior['net_units_sold'], (float) $current['net_units_sold']),
        ];
    }

    /**
     * Percentage change, or null when there is no prior figure to compare to.
     */
    public static function percentChange(float $previous, float $current): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return (($current - $previous) / abs($previous)) * 100;
    }
}
