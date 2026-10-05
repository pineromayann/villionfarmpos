<?php

namespace App\Analytics;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Procurement: what was ordered, what arrived, and what it cost.
 *
 * Ordered and received are reported separately because they are not the same
 * event. An order on the books is an intention; only a receipt has moved stock
 * and created a cost. Collapsing them would show money committed as money
 * spent, which is the difference a supplier reconciliation turns on.
 *
 * Received spend is dated by `received_at`, falling back to `order_date` for
 * orders that predate receipt tracking.
 */
class ProcurementAnalytics extends AnalyticsQuery
{
    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsFilters $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'trend' => $this->trend($filters),
            'bySupplier' => $this->bySupplier($filters),
            'byProduct' => $this->byProduct($filters),
            'byStatus' => $this->byStatus($filters),
            'openOrders' => $this->openOrders(),
            'leadTimes' => $this->leadTimes($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(AnalyticsFilters $filters): array
    {
        $ordered = $this->orders($filters, ['ordered', 'received']);
        $received = $this->orders($filters, ['received']);
        $cancelled = $this->orders($filters, ['cancelled']);

        $orderedValue = (float) $ordered->sum('purchase_orders.total');
        $receivedValue = (float) $received->sum('purchase_orders.total');
        $cancelledValue = (float) $cancelled->sum('purchase_orders.total');
        $awaiting = $this->openOrders();
        $awaitingValue = (float) $awaiting->sum('total');

        return [
            'orders' => $ordered->count(),
            'ordered_value' => $orderedValue,
            'received_orders' => $received->count(),
            'received_value' => $receivedValue,
            'cancelled_orders' => $cancelled->count(),
            'cancelled_value' => $cancelledValue,
            'awaiting_orders' => $awaiting->count(),
            'awaiting_value' => $awaitingValue,
            'average_order' => $ordered->count() > 0 ? $orderedValue / $ordered->count() : 0.0,
            'suppliers_used' => $this->orders($filters)->distinct()->count('purchase_orders.supplier_id'),
        ];
    }

    /**
     * Ordered and received value per bucket.
     *
     * @return array<int, array{label: string, ordered: float, received: float}>
     */
    public function trend(AnalyticsFilters $filters): array
    {
        $driver = DB::connection()->getDriverName();

        $expression = fn (string $column): string => $driver === 'sqlite'
            ? match ($filters->period->grouping()) {
                'week' => "DATE({$column}, '-' || ((CAST(STRFTIME('%w', {$column}) AS INTEGER) + 6) % 7) || ' days')",
                'month' => "STRFTIME('%Y-%m-01', {$column})",
                default => "DATE({$column})",
            }
        : match ($filters->period->grouping()) {
            'week' => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            default => "DATE({$column})",
        };

        $orderedExpression = $expression('purchase_orders.order_date');
        $receivedExpression = $expression('COALESCE(purchase_orders.received_at, purchase_orders.order_date)');

        $orderedRows = $this->orders($filters, ['ordered', 'received'])
            ->groupByRaw($orderedExpression)
            ->select([
                DB::raw("{$orderedExpression} AS bucket"),
                DB::raw('SUM(purchase_orders.total) AS value'),
            ])
            ->get()
            ->keyBy('bucket');

        $receivedRows = $this->orders($filters, ['received'])
            ->groupByRaw($receivedExpression)
            ->select([
                DB::raw("{$receivedExpression} AS bucket"),
                DB::raw('SUM(purchase_orders.total) AS value'),
            ])
            ->get()
            ->keyBy('bucket');

        return array_map(function (string $bucket) use ($orderedRows, $receivedRows, $filters): array {
            return [
                'label' => $this->bucketLabel($bucket, $filters->period->grouping()),
                'ordered' => (float) ($orderedRows[$bucket]->value ?? 0),
                'received' => (float) ($receivedRows[$bucket]->value ?? 0),
            ];
        }, $this->periodBuckets($filters));
    }

    /**
     * Spend per supplier, restricted to received orders so the figure matches
     * what actually landed in stock.
     *
     * @return array<int, array{id: int, name: string, orders: int, value: float, average: float}>
     */
    public function bySupplier(AnalyticsFilters $filters): array
    {
        $rows = $this->orders($filters, ['received'])
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->groupBy('purchase_orders.supplier_id', 'suppliers.name')
            ->select([
                'purchase_orders.supplier_id',
                'suppliers.name',
                DB::raw('COUNT(*) AS orders'),
                DB::raw('SUM(purchase_orders.total) AS value'),
            ])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->supplier_id,
                'name' => (string) $row->name,
                'orders' => (int) $row->orders,
                'value' => (float) $row->value,
                'average' => (int) $row->orders > 0 ? (float) $row->value / (int) $row->orders : 0.0,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Most purchased products, by received spend.
     *
     * @return array<int, array{id: int, name: string, quantity: float, value: float}>
     */
    public function byProduct(AnalyticsFilters $filters, int $limit = 10): array
    {
        $rows = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_orders.status', 'received')
            ->whereBetween(
                DB::raw('COALESCE(purchase_orders.received_at, purchase_orders.order_date)'),
                [$filters->period->from, $filters->period->to],
            )
            ->when($filters->supplierId !== null, fn (Builder $query) => $query->where('purchase_orders.supplier_id', $filters->supplierId))
            ->when($filters->category !== null, fn (Builder $query) => $query->where('products.category', $filters->category))
            ->when($filters->productId !== null, fn (Builder $query) => $query->where('purchase_order_items.product_id', $filters->productId))
            ->groupBy('purchase_order_items.product_id', 'products.name')
            ->select([
                'purchase_order_items.product_id',
                'products.name',
                DB::raw('SUM(purchase_order_items.quantity) AS quantity'),
                DB::raw('SUM(purchase_order_items.line_total) AS value'),
            ])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'quantity' => (float) $row->quantity,
                'value' => (float) $row->value,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        return array_slice($rows, 0, $limit);
    }

    /**
     * Order counts by status, so the pipeline is legible at a glance.
     *
     * @return array<int, array{label: string, orders: int, value: float}>
     */
    public function byStatus(AnalyticsFilters $filters): array
    {
        $rows = $this->orders($filters, PurchaseOrder::STATUSES)
            ->groupBy('purchase_orders.status')
            ->select([
                'purchase_orders.status',
                DB::raw('COUNT(*) AS orders'),
                DB::raw('SUM(purchase_orders.total) AS value'),
            ])
            ->get()
            ->keyBy('status');

        return array_map(fn (string $status) => [
            'label' => ucfirst($status),
            'orders' => (int) ($rows[$status]->orders ?? 0),
            'value' => (float) ($rows[$status]->value ?? 0),
        ], PurchaseOrder::STATUSES);
    }

    /**
     * Orders still awaiting delivery. Scoped to now rather than the reporting
     * period: an order placed last month that is still outstanding is a live
     * problem, not a historical figure.
     *
     * @return Collection<int, array{id: int, supplier: string, total: float, order_date: string, expected_date: string|null, days_open: int, is_overdue: bool}>
     */
    public function openOrders(): Collection
    {
        return PurchaseOrder::query()
            ->where('status', 'ordered')
            ->with('supplier')
            ->orderBy('order_date')
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'supplier' => $order->supplier?->name ?? 'Unknown supplier',
                'total' => (float) $order->total,
                'order_date' => $order->order_date?->toDateString() ?? '',
                'expected_date' => $order->expected_date?->toDateString(),
                'days_open' => $order->order_date?->diffInDays(now()) ?? 0,
                'is_overdue' => $order->expected_date !== null && $order->expected_date->isPast(),
            ]);
    }

    /**
     * How long suppliers actually take, from the average of orders received in
     * the period. Null when no order in the period has been received.
     *
     * @return array<int, array{id: int, name: string, orders: int, average_days: float|null, latest_days: int|null}>
     */
    public function bySupplierLeadTime(AnalyticsFilters $filters): array
    {
        $daysExpression = DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST(JULIANDAY(received_at) - JULIANDAY(order_date) AS INTEGER)'
            : 'DATEDIFF(received_at, order_date)';

        $rows = $this->orders($filters, ['received'])
            ->whereNotNull('purchase_orders.received_at')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->groupBy('purchase_orders.supplier_id', 'suppliers.name')
            ->select([
                'purchase_orders.supplier_id',
                'suppliers.name',
                DB::raw('COUNT(*) AS orders'),
                DB::raw("AVG({$daysExpression}) AS average_days"),
                DB::raw("MAX({$daysExpression}) AS latest_days"),
            ])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->supplier_id,
                'name' => (string) $row->name,
                'orders' => (int) $row->orders,
                'average_days' => $row->average_days === null ? null : round((float) $row->average_days, 1),
                'latest_days' => $row->latest_days === null ? null : (int) $row->latest_days,
            ])
            ->sortBy('average_days')
            ->values()
            ->all();

        return $rows;
    }

    /**
     * @return array<int, array{id: int, name: string, orders: int, average_days: float|null, latest_days: int|null}>
     */
    public function leadTimes(AnalyticsFilters $filters): array
    {
        return $this->bySupplierLeadTime($filters);
    }

    /**
     * Purchase orders in the period, filtered to the given statuses.
     *
     * @param  array<int, string>|null  $statuses
     * @return Builder<Builder>
     */
    private function orders(AnalyticsFilters $filters, ?array $statuses = null): Builder
    {
        return DB::table('purchase_orders')
            ->whereBetween(
                DB::raw('COALESCE(purchase_orders.received_at, purchase_orders.order_date)'),
                [$filters->period->from, $filters->period->to],
            )
            ->when($statuses !== null, fn (Builder $query) => $query->whereIn('purchase_orders.status', $statuses))
            ->when($filters->supplierId !== null, fn (Builder $query) => $query->where('purchase_orders.supplier_id', $filters->supplierId))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('purchase_orders.status', $filters->status));
    }

    /**
     * Suppliers for the filter dropdown.
     *
     * @return array<int, string>
     */
    public function supplierOptions(): array
    {
        return Supplier::orderBy('name')->pluck('name', 'id')->all();
    }
}
