<?php

namespace App\Analytics;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use Illuminate\Http\Request;

/**
 * The filters every analytics page accepts.
 *
 * Built once from the request and handed to the analytics services, so the
 * dashboard, the drill-down reports and their exports are always reading the
 * same slice of data. Values are validated here rather than in each service so
 * an unknown category id cannot reach a query.
 *
 * Some filters are not applicable to every page — `partner_id` is meaningless
 * on the procurement report — but keeping one object for all pages means a
 * shared filter bar can post the same query string everywhere, and each service
 * simply ignores what does not apply to it.
 */
class AnalyticsFilters
{
    private function __construct(
        public readonly AnalyticsPeriod $period,
        public readonly ?string $category,
        public readonly ?int $productId,
        public readonly ?int $supplierId,
        public readonly ?int $partnerId,
        public readonly ?int $customerId,
        public readonly ?string $paymentMethod,
        public readonly ?string $status,
    ) {
        //
    }

    public static function fromRequest(Request $request, string $defaultPeriod = AnalyticsPeriod::DEFAULT_PRESET): self
    {
        return new self(
            period: AnalyticsPeriod::fromRequest($request, $defaultPeriod),
            category: self::category($request),
            productId: self::integer($request, 'product_id'),
            supplierId: self::integer($request, 'supplier_id'),
            partnerId: self::integer($request, 'partner_id'),
            customerId: self::integer($request, 'customer_id'),
            paymentMethod: self::paymentMethod($request),
            status: self::status($request),
        );
    }

    /**
     * The same filters against a different window, used for period-on-period
     * reads so a comparison can never silently drop a narrowing filter.
     */
    public function withPeriod(AnalyticsPeriod $period): self
    {
        return new self(
            period: $period,
            category: $this->category,
            productId: $this->productId,
            supplierId: $this->supplierId,
            partnerId: $this->partnerId,
            customerId: $this->customerId,
            paymentMethod: $this->paymentMethod,
            status: $this->status,
        );
    }

    private static function category(Request $request): ?string
    {
        $category = $request->query('category');

        return is_string($category) && in_array($category, Product::CATEGORIES, true)
            ? $category
            : null;
    }

    private static function integer(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private static function paymentMethod(Request $request): ?string
    {
        $method = $request->query('payment_method');

        return is_string($method) && in_array($method, Sale::PAYMENT_METHODS, true)
            ? $method
            : null;
    }

    private static function status(Request $request): ?string
    {
        $status = $request->query('status');

        return is_string($status) && in_array($status, PurchaseOrder::STATUSES, true)
            ? $status
            : null;
    }

    /**
     * Whether any narrowing filter is applied beyond the date range.
     */
    public function hasNarrowingFilter(): bool
    {
        return $this->category !== null
            || $this->productId !== null
            || $this->supplierId !== null
            || $this->partnerId !== null
            || $this->customerId !== null
            || $this->paymentMethod !== null
            || $this->status !== null;
    }

    /**
     * The query string carried between pages and exports, so a drill-down or a
     * CSV keeps the filters that produced it.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $query = ['period' => $this->period->preset];

        if ($this->period->preset === 'custom') {
            $query['date_from'] = $this->period->from->toDateString();
            $query['date_to'] = $this->period->to->toDateString();
        }

        foreach ([
            'category' => $this->category,
            'product_id' => $this->productId,
            'supplier_id' => $this->supplierId,
            'partner_id' => $this->partnerId,
            'customer_id' => $this->customerId,
            'payment_method' => $this->paymentMethod,
            'status' => $this->status,
        ] as $key => $value) {
            if ($value !== null) {
                $query[$key] = (string) $value;
            }
        }

        return $query;
    }

    /**
     * The active filters as label/value pairs for a summary line.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        $labels = [];

        if ($this->category !== null) {
            $labels['Category'] = ucfirst($this->category);
        }

        if ($this->paymentMethod !== null) {
            $labels['Payment'] = str_replace('_', ' ', ucfirst($this->paymentMethod));
        }

        if ($this->status !== null) {
            $labels['Status'] = ucfirst($this->status);
        }

        foreach ([
            'product_id' => 'Product',
            'supplier_id' => 'Supplier',
            'partner_id' => 'Partner',
            'customer_id' => 'Customer',
        ] as $key => $label) {
            if ($this->{$key} !== null) {
                $labels[$label] = (string) $this->{$key};
            }
        }

        return $labels;
    }
}
