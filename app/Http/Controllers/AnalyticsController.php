<?php

namespace App\Http\Controllers;

use App\Analytics\AnalyticsContext;
use App\Analytics\AnalyticsFilters;
use App\Analytics\ConsignmentAnalytics;
use App\Analytics\InventoryAnalytics;
use App\Analytics\ProcurementAnalytics;
use App\Analytics\ProductPerformanceAnalytics;
use App\Analytics\ProfitAnalytics;
use App\Analytics\SalesAnalytics;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entry point for the analytics section.
 *
 * The controller stays deliberately thin: it resolves the shared filter state,
 * asks the relevant analytics class for a report, and hands both to a view.
 * All calculation lives in App\Analytics so it can be tested and reused by the
 * export endpoints without going through a request.
 *
 * Each page is behind its own permission, so a cashier can see sales without
 * seeing the shop's margins or what the partners are owed.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request, SalesAnalytics $sales, InventoryAnalytics $inventory): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        $summary = $sales->summary($filters);

        $report = $sales->report($filters);

        return view('analytics.index', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'summary' => $summary,
            'trend' => $report['trend'],
            'report' => $report,
            'profit' => app(ProfitAnalytics::class)->summary($filters),
            'inventory' => $inventory->summary(),
            'consignment' => app(ConsignmentAnalytics::class)->summary($filters),
            'procurement' => app(ProcurementAnalytics::class)->summary($filters),
            'topProducts' => $sales->topProducts($filters, 5),
            'topCustomers' => $sales->topCustomers($filters, 5),
            'byCategory' => $sales->byCategory($filters),
        ]);
    }

    public function sales(Request $request, SalesAnalytics $analytics): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return view('analytics.sales', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'report' => $analytics->report($filters),
        ]);
    }

    public function grossProfit(Request $request, ProfitAnalytics $analytics): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return view('analytics.gross-profit', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'report' => $analytics->report($filters),
        ]);
    }

    public function inventory(Request $request, InventoryAnalytics $analytics): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return view('analytics.inventory', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'report' => $analytics->report($filters),
        ]);
    }

    public function procurement(Request $request, ProcurementAnalytics $analytics): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return view('analytics.procurement', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'report' => $analytics->report($filters),
        ]);
    }

    public function consignment(Request $request, ConsignmentAnalytics $analytics): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return view('analytics.consignment', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'report' => $analytics->report($filters),
        ]);
    }

    public function products(Request $request, ProductPerformanceAnalytics $analytics): View
    {
        $filters = AnalyticsFilters::fromRequest($request);

        $sort = (string) $request->query('sort', 'revenue');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, ProductPerformanceAnalytics::SORTABLE, true)) {
            $sort = 'revenue';
        }

        $rows = $analytics->sortedRows($filters, $sort, $direction);

        return view('analytics.products', [
            'filters' => $filters,
            'options' => app(AnalyticsContext::class)->options(),
            'report' => [
                ...$analytics->report($filters),
                'rows' => $rows,
            ],
            'products' => $this->paginateRows($rows)->withQueryString(),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Paginate rows that were assembled in PHP rather than queried directly.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function paginateRows(array $rows, int $perPage = 25): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    public function salesCsv(Request $request, SalesAnalytics $analytics): StreamedResponse
    {
        $filters = AnalyticsFilters::fromRequest($request);
        $report = $analytics->report($filters);

        return $this->streamCsv(
            'sales-analytics-'.$filters->period->preset.'.csv',
            ['Bucket', 'Label', 'Net revenue', 'Transactions'],
            collect($report['trend'])->map(fn (array $row): array => [
                $filters->period->preset,
                $row['label'],
                number_format((float) $row['value'], 2),
                $row['transactions'],
            ]),
        );
    }

    public function grossProfitCsv(Request $request, ProfitAnalytics $analytics): StreamedResponse
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return $this->streamCsv(
            'gross-profit-analytics-'.$filters->period->preset.'.csv',
            ['Product', 'Revenue', 'Cost', 'Gross profit', 'Margin %'],
            collect($analytics->byProduct($filters, PHP_INT_MAX))->map(fn (array $row): array => [
                $row['label'],
                number_format((float) $row['value'], 2),
                number_format((float) $row['cost'], 2),
                number_format((float) $row['gross_profit'], 2),
                number_format((float) $row['margin'], 2),
            ]),
        );
    }

    public function inventoryCsv(InventoryAnalytics $analytics): StreamedResponse
    {
        return $this->streamCsv(
            'inventory-analytics.csv',
            ['Category', 'Products', 'Units on hand', 'Stock value'],
            collect($analytics->byCategory())->map(fn (array $row): array => [
                $row['label'],
                $row['products'],
                Money::units((float) $row['units']),
                number_format((float) $row['value'], 2),
            ]),
        );
    }

    public function procurementCsv(Request $request, ProcurementAnalytics $analytics): StreamedResponse
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return $this->streamCsv(
            'procurement-analytics-'.$filters->period->preset.'.csv',
            ['Supplier', 'Orders received', 'Value received'],
            collect($analytics->bySupplier($filters))->map(fn (array $row): array => [
                $row['name'],
                $row['orders'],
                number_format((float) $row['value'], 2),
            ]),
        );
    }

    public function consignmentCsv(Request $request, ConsignmentAnalytics $analytics): StreamedResponse
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return $this->streamCsv(
            'consignment-analytics-'.$filters->period->preset.'.csv',
            ['Partner', 'Units sold', 'Retail', 'Payable', 'Commission', 'Balance due'],
            collect($analytics->byPartner($filters))->map(fn (array $row): array => [
                $row['name'],
                Money::units((float) $row['units']),
                number_format((float) $row['retail'], 2),
                number_format((float) $row['payable'], 2),
                number_format((float) $row['commission'], 2),
                number_format((float) $row['balance_due'], 2),
            ]),
        );
    }

    public function productsCsv(Request $request, ProductPerformanceAnalytics $analytics): StreamedResponse
    {
        $filters = AnalyticsFilters::fromRequest($request);

        $sort = (string) $request->query('sort', 'revenue');
        $direction = (string) $request->query('direction', 'desc');

        return $this->streamCsv(
            'product-performance-'.$filters->period->preset.'.csv',
            ['Product', 'Category', 'Units sold', 'Stock on hand', 'Revenue', 'Cost', 'Gross profit', 'Margin %', 'Stock value'],
            collect($analytics->sortedRows($filters, $sort, $direction))->map(fn (array $row): array => [
                $row['name'],
                $row['category'],
                Money::units((float) $row['units_sold']),
                Money::units((float) $row['stock_on_hand']),
                number_format((float) $row['revenue'], 2),
                $row['cost'] === null ? 'Unknown' : number_format((float) $row['cost'], 2),
                $row['gross_profit'] === null ? 'Unknown' : number_format((float) $row['gross_profit'], 2),
                $row['margin'] === null ? 'Unknown' : number_format((float) $row['margin'], 2),
                number_format((float) $row['stock_value'], 2),
            ]),
        );
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function streamCsv(string $filename, array $headers, Collection $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
