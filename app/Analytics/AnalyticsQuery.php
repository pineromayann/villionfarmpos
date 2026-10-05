<?php

namespace App\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Query plumbing shared by the sales-facing analytics services.
 *
 * Sales and refunds are reported together, and the two are easy to get subtly
 * wrong independently: a sale's `subtotal` is money before the sale-level
 * discount, `total` is after it, and a refund only reduces money once. Keeping
 * the joins and the return netting in one place stops the gross profit page and
 * the sales page from quietly disagreeing about what was sold.
 *
 * Consigned lines are deliberately excluded from owned-stock revenue and cost.
 * A consignment sale is money the store collects on someone else's goods and
 * then owes back, so counting it as inventory sold would overstate both revenue
 * and cost of goods. Consignment reporting lives in ConsignmentAnalytics.
 */
abstract class AnalyticsQuery
{
    /**
     * Sale items sold from owned stock within the period.
     *
     * `consignment_sales.sale_item_id` is null for owned lines and points at
     * the item for consigned ones, so a left join plus an is-null test drops
     * exactly the consigned lines.
     *
     * @return Builder<Builder>
     */
    protected function ownedSaleItems(AnalyticsFilters $filters): Builder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin(
                'consignment_sales',
                fn ($join) => $join->on('consignment_sales.sale_item_id', '=', 'sale_items.id'),
            )
            ->whereNull('consignment_sales.id')
            ->whereBetween('sales.created_at', [$filters->period->from, $filters->period->to])
            ->when($filters->category !== null, fn (Builder $query) => $query->where('products.category', $filters->category))
            ->when($filters->productId !== null, fn (Builder $query) => $query->where('sale_items.product_id', $filters->productId))
            ->when($filters->customerId !== null, fn (Builder $query) => $query->where('sales.customer_id', $filters->customerId))
            ->when($filters->paymentMethod !== null, fn (Builder $query) => $query->where('sales.payment_method', $filters->paymentMethod));
    }

    /**
     * Refunds in the period against owned stock.
     *
     * @return Builder<Builder>
     */
    protected function ownedRefunds(AnalyticsFilters $filters): Builder
    {
        return DB::table('refunds')
            ->join('sales', 'sales.id', '=', 'refunds.sale_id')
            ->join('products', 'products.id', '=', 'refunds.product_id')
            ->leftJoin(
                'consignment_sales',
                fn ($join) => $join->on('consignment_sales.sale_item_id', '=', 'refunds.sale_item_id'),
            )
            ->whereNull('consignment_sales.id')
            ->whereBetween('refunds.created_at', [$filters->period->from, $filters->period->to])
            ->when($filters->category !== null, fn (Builder $query) => $query->where('products.category', $filters->category))
            ->when($filters->productId !== null, fn (Builder $query) => $query->where('refunds.product_id', $filters->productId))
            ->when($filters->customerId !== null, fn (Builder $query) => $query->where('sales.customer_id', $filters->customerId))
            ->when($filters->paymentMethod !== null, fn (Builder $query) => $query->where('sales.payment_method', $filters->paymentMethod));
    }

    /**
     * Sales in the period, before any return netting.
     *
     * @return Builder<Builder>
     */
    protected function salesInPeriod(AnalyticsFilters $filters): Builder
    {
        return DB::table('sales')
            ->whereBetween('created_at', [$filters->period->from, $filters->period->to])
            ->when($filters->paymentMethod !== null, fn (Builder $query) => $query->where('payment_method', $filters->paymentMethod))
            ->when($filters->customerId !== null, fn (Builder $query) => $query->where('customer_id', $filters->customerId));
    }

    /**
     * Net base units sold per product, after customer returns.
     *
     * Returns are matched against the period, not against the original sale,
     * because a refund is a cash event on the day it happens. Netting it
     * against the sale's own period would make a refund in June disappear from
     * a June report.
     *
     * @return array<int, float> product_id => net base units
     */
    protected function netQuantityByProduct(AnalyticsFilters $filters): array
    {
        $sold = $this->ownedSaleItems($filters)
            ->groupBy('sale_items.product_id')
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) AS quantity'))
            ->pluck('quantity', 'product_id');

        $returned = $this->ownedRefunds($filters)
            ->groupBy('refunds.product_id')
            ->select('refunds.product_id', DB::raw('SUM(refunds.quantity) AS quantity'))
            ->pluck('quantity', 'product_id');

        $net = [];

        foreach ($sold->keys() as $productId) {
            $net[(int) $productId] = (float) $sold[$productId] - (float) ($returned[$productId] ?? 0);
        }

        return $net;
    }

    /**
     * SQL expression that truncates a sales timestamp to its bucket start.
     *
     * The application runs on MySQL but its tests run on SQLite, and the two
     * disagree on date functions, so the expression is chosen per driver rather
     * than hard-coded to whichever database development happens to use.
     */
    protected function salesDateBucket(AnalyticsFilters $filters): string
    {
        $column = 'sales.created_at';

        return match (DB::connection()->getDriverName()) {
            'sqlite' => match ($filters->period->grouping()) {
                'week' => "DATE({$column}, '-' || ((CAST(STRFTIME('%w', {$column}) AS INTEGER) + 6) % 7) || ' days')",
                'month' => "STRFTIME('%Y-%m-01', {$column})",
                default => "DATE({$column})",
            },
            default => match ($filters->period->grouping()) {
                'week' => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
                'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
                default => "DATE({$column})",
            },
        };
    }

    /**
     * Human label for a bucket key produced by the date bucket expression.
     */
    public function bucketLabel(string $bucket, string $grouping): string
    {
        try {
            $date = Carbon::parse($bucket);
        } catch (\Throwable) {
            return $bucket;
        }

        return match ($grouping) {
            'week' => 'Week of '.$date->format('j M'),
            'month' => $date->format('M Y'),
            default => $date->format('j M'),
        };
    }

    /**
     * Every day (or week, or month) in the period, so a chart shows quiet days
     * rather than silently skipping them.
     *
     * @return array<int, string>
     */
    protected function periodBuckets(AnalyticsFilters $filters): array
    {
        $buckets = [];
        $cursor = $filters->period->from->copy()->startOfDay();
        $end = $filters->period->to->copy()->startOfDay();
        $grouping = $filters->period->grouping();

        while ($cursor->lessThanOrEqualTo($end)) {
            $buckets[] = match ($grouping) {
                'week' => $cursor->copy()->startOfWeek()->toDateString(),
                'month' => $cursor->copy()->startOfMonth()->toDateString(),
                default => $cursor->toDateString(),
            };

            match ($grouping) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return array_values(array_unique($buckets));
    }
}
