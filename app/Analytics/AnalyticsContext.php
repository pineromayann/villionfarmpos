<?php

namespace App\Analytics;

use App\ConsignmentStockService;
use App\Models\ConsignmentPartner;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Everything the analytics pages need that is not a calculation: filter options
 * and the aggregate headline shown at the top of every page.
 *
 * Keeping the option lists here means the filter bar on all seven pages stays
 * in step, and a product added this morning is immediately selectable on the
 * analytics pages without touching a view.
 */
class AnalyticsContext
{
    /**
     * The "at a glance" strip: today's takings and the live obligations.
     *
     * @return array<string, float|int>
     */
    public function headline(): array
    {
        return [
            'today_sales' => (float) Sale::whereDate('created_at', today())->sum('total'),
            'today_transactions' => Sale::whereDate('created_at', today())->count(),
            'month_sales' => (float) Sale::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total'),
            'stock_value' => (float) Product::sum(DB::raw('stock * COALESCE(NULLIF(cost_price, 0), price)')),
            'low_stock' => Product::lowStock()->count(),
            'outstanding_consignment' => ConsignmentStockService::totalBalanceDue(),
            'open_orders' => PurchaseOrder::where('status', 'ordered')->count(),
        ];
    }

    /**
     * Dropdown options shared by the analytics filter bar.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'categories' => Product::CATEGORIES,
            'products' => Product::orderBy('name')->pluck('name', 'id')->all(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id')->all(),
            'customers' => Customer::orderBy('name')->pluck('name', 'id')->all(),
            'partners' => ConsignmentPartner::orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => PurchaseOrder::STATUSES,
            'paymentMethods' => Sale::PAYMENT_METHODS,
        ];
    }
}
