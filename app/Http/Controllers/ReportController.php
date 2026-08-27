<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('reports.index');
    }

    public function salesPdf(Request $request): Response
    {
        [$sales, $range] = $this->filteredSales($request);

        return Pdf::loadView('reports.pdf.sales', $this->salesReportData($sales, $range))
            ->download('sales-report.pdf');
    }

    public function salesCsv(Request $request): StreamedResponse
    {
        [$sales] = $this->filteredSales($request);

        return $this->streamCsv(
            'sales-report.csv',
            ['Date', 'Customer', 'Items', 'Payment method', 'Subtotal', 'Discount', 'Total'],
            $sales->map(fn (Sale $sale) => [
                $sale->created_at->format('Y-m-d H:i'),
                $sale->customer?->name ?? 'Walk-in customer',
                $sale->itemCount(),
                Str::of($sale->payment_method)->replace('_', ' ')->title(),
                number_format((float) $sale->subtotal, 2),
                number_format((float) $sale->discount, 2),
                number_format((float) $sale->total, 2),
            ])
        );
    }

    public function inventoryPdf(): Response
    {
        return Pdf::loadView('reports.pdf.inventory', [
            'products' => Product::orderBy('name')->get(),
            'generatedAt' => now(),
        ])->download('inventory-report.pdf');
    }

    public function inventoryCsv(): StreamedResponse
    {
        $products = Product::orderBy('name')->get();

        return $this->streamCsv(
            'inventory-report.csv',
            ['Product', 'Active ingredient', 'Batch', 'Expiry date', 'Price', 'Stock', 'Unit', 'Low stock', 'Expiring soon'],
            $products->map(fn (Product $product) => [
                $product->name,
                $product->active_ingredient,
                $product->batch_number,
                $product->expiry_date?->format('Y-m-d'),
                number_format((float) $product->price, 2),
                $product->stock,
                $product->unit,
                $product->isLowStock() ? 'Yes' : 'No',
                $product->isExpiringSoon() ? 'Yes' : 'No',
            ])
        );
    }

    public function customersPdf(): Response
    {
        return Pdf::loadView('reports.pdf.customers', [
            'customers' => Customer::orderBy('name')->get(),
            'generatedAt' => now(),
        ])->download('customers-report.pdf');
    }

    public function customersCsv(): StreamedResponse
    {
        $customers = Customer::orderBy('name')->get();

        return $this->streamCsv(
            'customers-report.csv',
            ['Name', 'Farm', 'Phone', 'Location', 'Crop', 'Hectares', 'Lifetime spend'],
            $customers->map(fn (Customer $customer) => [
                $customer->name,
                $customer->farm_name,
                $customer->phone,
                $customer->location,
                $customer->crop,
                $customer->hectares,
                number_format($customer->lifetimeSpend(), 2),
            ])
        );
    }

    /**
     * @return array{0: Collection<int, Sale>, 1: array{from: ?Carbon, to: ?Carbon}}
     */
    private function filteredSales(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;

        $sales = Sale::with(['customer', 'items.product'])
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->latest()
            ->get();

        return [$sales, ['from' => $from, 'to' => $to]];
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @param  array{from: ?Carbon, to: ?Carbon}  $range
     * @return array<string, mixed>
     */
    private function salesReportData(Collection $sales, array $range): array
    {
        $topProducts = $sales
            ->flatMap(fn (Sale $sale) => $sale->items)
            ->groupBy('product_id')
            ->map(function (Collection $items) {
                $product = $items->first()->product;

                return [
                    'name' => $product?->name ?? 'Unknown product',
                    'units_sold' => $items->sum('quantity'),
                    'revenue' => $items->sum('line_total'),
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        return [
            'sales' => $sales,
            'range' => $range,
            'generatedAt' => now(),
            'totalRevenue' => $sales->sum('total'),
            'itemsSold' => $sales->flatMap->items->sum('quantity'),
            'topProducts' => $topProducts,
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  Collection<int, array<int, mixed>>  $rows
     */
    private function streamCsv(string $filename, array $headers, Collection $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
