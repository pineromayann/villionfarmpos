<?php

namespace App\Analytics;

use App\ConsignmentStockService;
use App\Models\Consignment;
use App\Models\ConsignmentPartner;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consignment: goods the shop holds on someone else's behalf.
 *
 * The shop's real earnings here are the commission — retail value less the
 * amount owed back to the partner. Counting the full retail value as revenue
 * and the goods as inventory would overstate both by the payable amount, which
 * is why consignment is reported apart from owned trading throughout analytics.
 *
 * On-hand balances and valuations come from ConsignmentStockService so this
 * report and the partner statements cannot drift apart.
 */
class ConsignmentAnalytics extends AnalyticsQuery
{
    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsFilters $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'trend' => $this->trend($filters),
            'byPartner' => $this->byPartner($filters),
            'byProduct' => $this->byProduct($filters),
            'adjustments' => $this->adjustments($filters),
            'settlements' => $this->settlements($filters),
            'balances' => $this->balances(),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function summary(AnalyticsFilters $filters): array
    {
        $sales = $this->sales($filters);
        $retail = (float) $sales->sum(DB::raw('consignment_sales.line_total - consignment_sales.refunded_line_total'));
        $payable = (float) $sales->sum(DB::raw('consignment_sales.payable_amount - consignment_sales.refunded_payable'));

        $receivedValue = (float) $this->receivedQuery($filters)->sum('consignment_items.line_total');
        $writtenOff = (float) $this->adjustmentsQuery($filters)->sum('consignment_adjustments.value');
        $settled = (float) $this->settlementsQuery($filters)->sum('consignment_settlements.amount');

        return [
            'partners' => ConsignmentPartner::count(),
            'received_orders' => $this->receivedQuery($filters)->count(),
            'received_units' => (float) $this->receivedQuery($filters)->sum(DB::raw('consignment_items.quantity')),
            'received_value' => $receivedValue,
            'sold_units' => (float) $sales->sum(DB::raw('consignment_sales.quantity - consignment_sales.refunded_quantity')),
            'retail_value' => $retail,
            'payable' => $payable,
            'commission' => $retail - $payable,
            'commission_rate' => $retail > 0 ? ((($retail - $payable) / $retail) * 100) : 0.0,
            'written_off_value' => $writtenOff,
            'settled' => $settled,
            'outstanding' => ConsignmentStockService::totalBalanceDue(),
        ];
    }

    /**
     * Retail, payable and commission per bucket.
     *
     * @return array<int, array{label: string, retail: float, commission: float}>
     */
    public function trend(AnalyticsFilters $filters): array
    {
        $driver = DB::connection()->getDriverName();

        $expression = $driver === 'sqlite'
            ? match ($filters->period->grouping()) {
                'week' => "DATE(consignment_sales.sold_at, '-' || ((CAST(STRFTIME('%w', consignment_sales.sold_at) AS INTEGER) + 6) % 7) || ' days')",
                'month' => "STRFTIME('%Y-%m-01', consignment_sales.sold_at)",
                default => 'DATE(consignment_sales.sold_at)',
            }
        : match ($filters->period->grouping()) {
            'week' => 'DATE(DATE_SUB(consignment_sales.sold_at, INTERVAL WEEKDAY(consignment_sales.sold_at) DAY))',
            'month' => "DATE_FORMAT(consignment_sales.sold_at, '%Y-%m-01')",
            default => 'DATE(consignment_sales.sold_at)',
        };

        $rows = $this->sales($filters)
            ->groupByRaw($expression)
            ->select([
                DB::raw("{$expression} AS bucket"),
                DB::raw('SUM(consignment_sales.line_total - consignment_sales.refunded_line_total) AS retail'),
                DB::raw('SUM(consignment_sales.payable_amount - consignment_sales.refunded_payable) AS payable'),
            ])
            ->get()
            ->keyBy('bucket');

        return array_map(function (string $bucket) use ($rows, $filters): array {
            $row = $rows[$bucket] ?? null;
            $retail = (float) ($row->retail ?? 0);

            return [
                'label' => $this->bucketLabel($bucket, $filters->period->grouping()),
                'retail' => $retail,
                'commission' => $retail - (float) ($row->payable ?? 0),
            ];
        }, $this->periodBuckets($filters));
    }

    /**
     * Per-partner performance and settlement position.
     *
     * @return array<int, array{id: int, name: string, units: float, retail: float, payable: float, commission: float, balance_due: float}>
     */
    public function byPartner(AnalyticsFilters $filters): array
    {
        $sales = $this->sales($filters)
            ->groupBy('consignment_sales.partner_id')
            ->select([
                'consignment_sales.partner_id',
                DB::raw('SUM(consignment_sales.quantity - consignment_sales.refunded_quantity) AS units'),
                DB::raw('SUM(consignment_sales.line_total - consignment_sales.refunded_line_total) AS retail'),
                DB::raw('SUM(consignment_sales.payable_amount - consignment_sales.refunded_payable) AS payable'),
            ])
            ->get()
            ->keyBy('partner_id');

        $partners = ConsignmentPartner::orderBy('name')->get();

        return $partners->map(function (ConsignmentPartner $partner) use ($sales): array {
            $row = $sales[$partner->id] ?? null;
            $retail = (float) ($row->retail ?? 0);
            $payable = (float) ($row->payable ?? 0);

            return [
                'id' => $partner->id,
                'name' => $partner->name,
                'units' => (float) ($row->units ?? 0),
                'retail' => $retail,
                'payable' => $payable,
                'commission' => $retail - $payable,
                'balance_due' => ConsignmentStockService::balanceDue($partner),
            ];
        })->all();
    }

    /**
     * Best selling consigned products.
     *
     * @return array<int, array{id: int, name: string, units: float, retail: float, commission: float}>
     */
    public function byProduct(AnalyticsFilters $filters, int $limit = 10): array
    {
        $rows = $this->sales($filters)
            ->groupBy('consignment_sales.product_id', 'products.name')
            ->select([
                'consignment_sales.product_id',
                'products.name',
                DB::raw('SUM(consignment_sales.quantity - consignment_sales.refunded_quantity) AS units'),
                DB::raw('SUM(consignment_sales.line_total - consignment_sales.refunded_line_total) AS retail'),
                DB::raw('SUM(consignment_sales.payable_amount - consignment_sales.refunded_payable) AS payable'),
            ])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'units' => (float) $row->units,
                'retail' => (float) $row->retail,
                'commission' => (float) $row->retail - (float) $row->payable,
            ])
            ->sortByDesc('retail')
            ->values()
            ->all();

        return array_slice($rows, 0, $limit);
    }

    /**
     * Damage, loss and write-offs by reason, which is where consignment risk
     * actually shows up.
     *
     * @return array<int, array{label: string, entries: int, units: float, value: float}>
     */
    public function adjustments(AnalyticsFilters $filters): array
    {
        return $this->adjustmentsQuery($filters)
            ->groupBy('consignment_adjustments.reason')
            ->select([
                'consignment_adjustments.reason',
                DB::raw('COUNT(*) AS entries'),
                DB::raw('SUM(consignment_adjustments.quantity) AS units'),
                DB::raw('SUM(consignment_adjustments.value) AS value'),
            ])
            ->get()
            ->map(fn ($row) => [
                'label' => ucfirst((string) ($row->reason ?? 'Other')),
                'entries' => (int) $row->entries,
                'units' => (float) $row->units,
                'value' => (float) $row->value,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * Cash settled with partners in the period.
     *
     * @return array<int, array{label: string, entries: int, value: float}>
     */
    public function settlements(AnalyticsFilters $filters): array
    {
        $total = (float) $this->settlementsQuery($filters)->sum('consignment_settlements.amount');

        return [[
            'label' => 'Settled',
            'entries' => $this->settlementsQuery($filters)->count(),
            'value' => $total,
        ]];
    }

    /**
     * What the shop currently owes each partner, at consignment cost.
     *
     * @return array<int, array{id: int, name: string, balance_due: float}>
     */
    public function balances(): array
    {
        return ConsignmentPartner::orderBy('name')->get()
            ->map(fn (ConsignmentPartner $partner) => [
                'id' => $partner->id,
                'name' => $partner->name,
                'balance_due' => ConsignmentStockService::balanceDue($partner),
            ])
            ->all();
    }

    /**
     * Consignment sales in the period.
     *
     * @return Builder<Builder>
     */
    private function sales(AnalyticsFilters $filters): Builder
    {
        return DB::table('consignment_sales')
            ->join('products', 'products.id', '=', 'consignment_sales.product_id')
            ->whereBetween('consignment_sales.sold_at', [$filters->period->from, $filters->period->to])
            ->when($filters->partnerId !== null, fn (Builder $query) => $query->where('consignment_sales.partner_id', $filters->partnerId))
            ->when($filters->productId !== null, fn (Builder $query) => $query->where('consignment_sales.product_id', $filters->productId))
            ->when($filters->category !== null, fn (Builder $query) => $query->where('products.category', $filters->category));
    }

    /**
     * @return Builder<Builder>
     */
    private function adjustmentsQuery(AnalyticsFilters $filters): Builder
    {
        return DB::table('consignment_adjustments')
            ->whereBetween('adjusted_at', [$filters->period->from, $filters->period->to])
            ->when($filters->partnerId !== null, fn (Builder $query) => $query->where('partner_id', $filters->partnerId));
    }

    /**
     * @return Builder<Builder>
     */
    private function settlementsQuery(AnalyticsFilters $filters): Builder
    {
        return DB::table('consignment_settlements')
            ->whereBetween('settled_at', [$filters->period->from, $filters->period->to])
            ->when($filters->partnerId !== null, fn (Builder $query) => $query->where('partner_id', $filters->partnerId));
    }

    /**
     * @return Builder<Builder>
     */
    private function receivedQuery(AnalyticsFilters $filters): Builder
    {
        return DB::table('consignment_items')
            ->join('consignments', 'consignments.id', '=', 'consignment_items.consignment_id')
            ->whereBetween('consignments.received_at', [$filters->period->from, $filters->period->to])
            ->when($filters->partnerId !== null, fn (Builder $query) => $query->where('consignments.partner_id', $filters->partnerId))
            ->when($filters->productId !== null, fn (Builder $query) => $query->where('consignment_items.product_id', $filters->productId));
    }

    /**
     * Partners for the filter dropdown.
     *
     * @return array<int, string>
     */
    public function partnerOptions(): array
    {
        return ConsignmentPartner::orderBy('name')->pluck('name', 'id')->all();
    }
}
