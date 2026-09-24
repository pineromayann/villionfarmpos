<?php

namespace App\Http\Controllers;

use App\ConsignmentStockService;
use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
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
        return view('reports.index', [
            'suppliers' => Supplier::orderBy('name')->get(),
            'consignmentPartners' => ConsignmentPartner::orderBy('name')->get(),
        ]);
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

    public function inventoryPdf(Request $request): Response
    {
        $category = $this->categoryFrom($request);

        return Pdf::loadView('reports.pdf.inventory', [
            'products' => $this->filteredProducts($category),
            'category' => $category,
            'generatedAt' => now(),
        ])->download('inventory-report.pdf');
    }

    public function inventoryCsv(Request $request): StreamedResponse
    {
        $category = $this->categoryFrom($request);
        $products = $this->filteredProducts($category);

        return $this->streamCsv(
            'inventory-report.csv',
            ['Product', 'Category', 'Active ingredient', 'Batch', 'Expiry date', 'Price', 'Stock', 'Unit', 'Low stock', 'Expiring soon'],
            $products->map(fn (Product $product) => [
                $product->name,
                $product->category ?? '',
                $product->active_ingredient,
                $product->batch_number,
                $product->expiry_date?->format('Y-m-d'),
                number_format($product->salePrice(), 2),
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

    public function stockPdf(Request $request): Response
    {
        [$movements, $range] = $this->filteredMovements($request);

        $totalIn = $movements->where('type', 'in')->sum('quantity');
        $totalOut = $movements->where('type', 'out')->sum('quantity');

        return Pdf::loadView('reports.pdf.stock-movements', [
            'movements' => $movements,
            'range' => $range,
            'generatedAt' => now(),
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
            'netChange' => $totalIn - $totalOut,
        ])->download('stock-movements-report.pdf');
    }

    public function stockCsv(Request $request): StreamedResponse
    {
        [$movements] = $this->filteredMovements($request);

        return $this->streamCsv(
            'stock-movements-report.csv',
            ['Date', 'Product', 'Type', 'Quantity', 'Reason', 'Source', 'Unit cost'],
            $movements->map(fn (StockMovement $movement) => [
                $movement->created_at->format('Y-m-d H:i'),
                $movement->product?->name ?? 'Unknown product',
                $movement->type,
                number_format((float) $movement->quantity, 2),
                $movement->reason ?? '',
                $movement->supplier?->name ?? ($movement->ref_type === 'sale' ? "Sale #{$movement->ref_id}" : ''),
                $movement->unit_cost !== null ? number_format((float) $movement->unit_cost, 2) : '',
            ])
        );
    }

    /**
     * @return array{0: Collection<int, Sale>, 1: array{from: ?Carbon, to: ?Carbon, category: ?string}}
     */
    private function filteredSales(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'category' => ['nullable', 'in:'.implode(',', Product::CATEGORIES)],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;
        $category = $validated['category'] ?? null;

        $sales = Sale::with(['customer', 'items.product'])
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->when($category, fn ($query) => $query->whereHas(
                'items.product',
                fn ($query) => $query->where('category', $category)
            ))
            ->latest()
            ->get();

        return [$sales, ['from' => $from, 'to' => $to, 'category' => $category]];
    }

    /**
     * @param  array{from: ?Carbon, to: ?Carbon, category: ?string}  $range
     */
    private function salesReportData(Collection $sales, array $range): array
    {
        $saleIds = $sales->pluck('id');

        $refundedTotal = (float) Refund::whereIn('sale_id', $saleIds)->sum('line_total');

        $payableTotal = ConsignmentSale::whereIn('sale_id', $saleIds)
            ->get(['payable_amount', 'refunded_payable'])
            ->sum(fn (ConsignmentSale $sale) => (float) $sale->payable_amount - (float) $sale->refunded_payable);

        $grossRevenue = (float) $sales->sum('total');

        $refundStats = Refund::whereIn('sale_id', $saleIds)
            ->select('product_id')
            ->selectRaw('SUM(quantity) AS units_returned, SUM(line_total) AS returned_revenue')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $consignmentStats = ConsignmentSale::whereIn('sale_id', $saleIds)
            ->get(['product_id', 'payable_amount', 'refunded_payable'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum(fn (ConsignmentSale $sale) => (float) $sale->payable_amount - (float) $sale->refunded_payable));

        $topProducts = $sales
            ->flatMap(fn (Sale $sale) => $sale->items)
            ->groupBy('product_id')
            ->map(function (Collection $items) use ($refundStats, $consignmentStats) {
                $sold = (float) $items->sum('quantity');
                $lineTotal = (float) $items->sum('line_total');
                $product = $items->first()->product;
                $productId = $items->first()->product_id;
                $returned = (float) ($refundStats->get($productId)?->units_returned ?? 0);
                $returnedRevenue = (float) ($refundStats->get($productId)?->returned_revenue ?? 0);
                $payable = (float) ($consignmentStats->get($productId) ?? 0);

                return [
                    'name' => $product?->name ?? 'Unknown product',
                    'units_sold' => $sold - $returned,
                    'revenue' => $lineTotal - $returnedRevenue - $payable,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        return [
            'sales' => $sales,
            'range' => $range,
            'generatedAt' => now(),
            'totalRevenue' => $grossRevenue,
            'earnedRevenue' => $grossRevenue - $refundedTotal - $payableTotal,
            'refundedTotal' => $refundedTotal,
            'consignmentPayable' => $payableTotal,
            'itemsSold' => $sales->flatMap->items->sum('quantity'),
            'topProducts' => $topProducts,
        ];
    }

    private function categoryFrom(Request $request): ?string
    {
        return $request->validate([
            'category' => ['nullable', 'in:'.implode(',', Product::CATEGORIES)],
        ])['category'] ?? null;
    }

    /**
     * @return array{0: Collection<int, StockMovement>, 1: array{from: ?Carbon, to: ?Carbon, category: ?string}}
     */
    private function filteredMovements(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'category' => ['nullable', 'in:'.implode(',', Product::CATEGORIES)],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;
        $category = $validated['category'] ?? null;

        $movements = StockMovement::with(['product', 'supplier'])
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->when($category, fn ($query) => $query->whereHas('product', fn ($query) => $query->where('category', $category)))
            ->latest()
            ->get();

        return [$movements, ['from' => $from, 'to' => $to, 'category' => $category]];
    }

    /**
     * @return Collection<int, Product>
     */
    private function filteredProducts(?string $category): Collection
    {
        return Product::when($category, fn ($query) => $query->byCategory($category))
            ->orderBy('name')
            ->get();
    }

    public function purchasesPdf(Request $request): Response
    {
        [$orders, $range] = $this->filteredPurchaseOrders($request);

        return Pdf::loadView('reports.pdf.purchases', [
            'orders' => $orders,
            'range' => $range,
            'generatedAt' => now(),
            'totalSpend' => $orders->where('status', '!=', 'cancelled')->sum('total'),
            'receivedSpend' => $orders->where('status', 'received')->sum('total'),
        ])->download('purchases-report.pdf');
    }

    public function purchasesCsv(Request $request): StreamedResponse
    {
        [$orders] = $this->filteredPurchaseOrders($request);

        return $this->streamCsv(
            'purchases-report.csv',
            ['Order', 'Order date', 'Supplier', 'Products', 'Status', 'Total'],
            $orders->map(fn (PurchaseOrder $order) => [
                "#{$order->id}",
                $order->order_date->format('Y-m-d'),
                $order->supplier?->name ?? '',
                $order->itemCount(),
                $order->status,
                number_format((float) $order->total, 2),
            ])
        );
    }

    public function consignmentPdf(Request $request): Response
    {
        [$rows, $range, $partners] = $this->filteredConsignment($request);

        return Pdf::loadView('reports.pdf.consignment', [
            'rows' => $rows,
            'range' => $range,
            'partners' => $partners,
            'generatedAt' => now(),
            'totalRetail' => $rows->sum(fn (ConsignmentSale $sale) => $sale->netLineTotal()),
            'totalPayable' => $rows->sum(fn (ConsignmentSale $sale) => $sale->netPayable()),
            'totalEarned' => $rows->sum(fn (ConsignmentSale $sale) => $sale->netLineTotal()) - $rows->sum(fn (ConsignmentSale $sale) => $sale->netPayable()),
            'totalReturned' => $rows->sum(fn (ConsignmentSale $sale) => (float) $sale->refunded_line_total),
            'totalDue' => ConsignmentStockService::totalBalanceDue(),
        ])->download('consignment-report.pdf');
    }

    public function consignmentCsv(Request $request): StreamedResponse
    {
        [$rows] = $this->filteredConsignment($request);

        return $this->streamCsv(
            'consignment-report.csv',
            ['Sold at', 'Partner', 'Product', 'Quantity', 'Returned', 'Unit price', 'Line total', 'Payable'],
            $rows->map(fn (ConsignmentSale $sale) => [
                $sale->sold_at->format('Y-m-d H:i'),
                $sale->partner?->name ?? '',
                $sale->product->name,
                number_format((float) $sale->quantity, 2),
                number_format((float) $sale->refunded_quantity, 2),
                number_format((float) $sale->unit_price, 2),
                number_format($sale->netLineTotal(), 2),
                number_format($sale->netPayable(), 2),
            ])
        );
    }

    /**
     * @return array{0: Collection<int, ConsignmentSale>, 1: array{from: ?Carbon, to: ?Carbon, partner_id: ?int}, 2: Collection<int, array<string, mixed>>}
     */
    private function filteredConsignment(Request $request): array
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'exists:consignment_partners,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;
        $partnerId = isset($validated['partner_id']) ? (int) $validated['partner_id'] : null;

        $rows = ConsignmentSale::with(['partner', 'product'])
            ->when($partnerId, fn ($query) => $query->where('partner_id', $partnerId))
            ->when($from, fn ($query) => $query->where('sold_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('sold_at', '<=', $to))
            ->latest('sold_at')
            ->get();

        $partners = ConsignmentPartner::orderBy('name')->get()->map(fn (ConsignmentPartner $partner) => [
            'name' => $partner->name,
            'soldPayable' => ConsignmentStockService::netPayable($partner),
            'adjustments' => (float) $partner->adjustments()->sum('value'),
            'settled' => (float) $partner->settlements()->sum('amount'),
            'balanceDue' => ConsignmentStockService::balanceDue($partner),
            'onHandValue' => ConsignmentStockService::onHandValue($partner),
        ]);

        return [$rows, ['from' => $from, 'to' => $to, 'partner_id' => $partnerId], $partners];
    }

    /**
     * @return array{0: Collection<int, PurchaseOrder>, 1: array{from: ?Carbon, to: ?Carbon, supplier_id: ?int}}
     */
    private function filteredPurchaseOrders(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null;
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null;
        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;

        $orders = PurchaseOrder::with(['supplier', 'items.product', 'items.unit'])
            ->when($from, fn ($query) => $query->where('order_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('order_date', '<=', $to))
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->latest()
            ->get();

        return [$orders, ['from' => $from, 'to' => $to, 'supplier_id' => $supplierId]];
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
