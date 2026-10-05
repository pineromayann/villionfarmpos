<?php

use App\Analytics\AnalyticsPeriod;
use App\Analytics\CostBasis;
use App\Analytics\InventoryAnalytics;
use App\Analytics\ProductPerformanceAnalytics;
use App\Models\Product;
use App\Models\StockMovement;

uses()->beforeEach(function () {
    $this->filters = analyticsFilters();
});

test('net sales subtract returns from owned line revenue', function () {
    $product = analyticsProduct();
    $sale = ownedSale($product, 4, 100);
    refundFor($sale, $product, 1, 100);

    $summary = salesAnalytics()->summary($this->filters);

    expect($summary['owned_revenue'])->toEqual(400.0)
        ->and($summary['returns'])->toEqual(100.0)
        ->and($summary['net_sales'])->toEqual(300.0)
        ->and($summary['net_units_sold'])->toEqual(3.0)
        ->and($summary['return_rate'])->toEqual(25.0);
});

test('a return is dated when it happens rather than against the original sale', function () {
    $product = analyticsProduct();
    $sale = ownedSale($product, 2, 100, ['created_at' => now()->subMonths(4)]);
    refundFor($sale, $product, 1, 100, ['created_at' => now()->subDays(2)]);

    $thisMonth = salesAnalytics()->summary($this->filters);
    $saleMonth = salesAnalytics()->summary(analyticsFilters([
        'period' => 'custom',
        'date_from' => now()->subMonths(4)->startOfMonth()->toDateString(),
        'date_to' => now()->subMonths(4)->endOfMonth()->toDateString(),
    ]));

    expect($thisMonth['returns'])->toEqual(100.0)
        ->and($thisMonth['owned_revenue'])->toEqual(0.0)
        ->and($saleMonth['owned_revenue'])->toEqual(200.0)
        ->and($saleMonth['returns'])->toEqual(0.0);
});

test('consigned lines are left out of owned revenue and gross profit', function () {
    $product = analyticsProduct();
    mixedConsignedSale($product, 2, 3, 100);

    $summary = salesAnalytics()->summary($this->filters);

    expect($summary['owned_revenue'])->toEqual(200.0)
        ->and($summary['has_consigned_lines'])->toBeTrue()
        ->and($summary['sales'])->toEqual(500.0);

    $profit = profitAnalytics()->summary($this->filters);

    expect($profit['revenue'])->toEqual(200.0)
        ->and($profit['cogs'])->toEqual(120.0);
});

test('the consigned flag respects the payment method filter', function () {
    $product = analyticsProduct();
    mixedConsignedSale($product, 2, 3, 100, ['payment_method' => 'cash']);

    expect(salesAnalytics()->summary($this->filters)['has_consigned_lines'])->toBeTrue()
        ->and(salesAnalytics()->summary(analyticsFilters(['payment_method' => 'card']))['has_consigned_lines'])
        ->toBeFalse();
});

test('gross profit uses the recorded stock-in cost rather than today\'s price', function () {
    $product = analyticsProduct(['cost_price' => 90]);
    stockReceivedAt($product, 100, 60);
    ownedSale($product, 2, 100);

    $profit = profitAnalytics()->summary($this->filters);

    expect($profit['cogs'])->toEqual(120.0)
        ->and($profit['gross_profit'])->toEqual(80.0)
        ->and($profit['margin'])->toEqual(40.0);
});

test('revenue without a cost is reported separately instead of being booked as profit', function () {
    $product = analyticsProduct(['cost_price' => 0]);
    ownedSale($product, 2, 100);

    $profit = profitAnalytics()->summary($this->filters);

    expect($profit['revenue'])->toEqual(0.0)
        ->and($profit['gross_profit'])->toEqual(0.0)
        ->and($profit['uncosted_products'])->toEqual(1)
        ->and($profit['excluded_revenue'])->toEqual(200.0);
});

test('a return against an uncosted product is excluded with it rather than deducted twice', function () {
    $costed = analyticsProduct();
    $uncosted = analyticsProduct(['cost_price' => 0]);

    $costedSale = ownedSale($costed, 2, 100);
    $uncostedSale = ownedSale($uncosted, 2, 100);

    refundFor($costedSale, $costed, 1, 100);
    refundFor($uncostedSale, $uncosted, 1, 100);

    $profit = profitAnalytics()->summary($this->filters);

    expect($profit['revenue'])->toEqual(100.0)
        ->and($profit['returns'])->toEqual(100.0)
        ->and($profit['returned_units'])->toEqual(1.0)
        ->and($profit['cogs'])->toEqual(60.0)
        ->and($profit['excluded_revenue'])->toEqual(100.0)
        ->and($profit['uncosted_products'])->toEqual(1);
});

test('the profit trend leaves uncosted products out just like the summary', function () {
    $costed = analyticsProduct();
    $uncosted = analyticsProduct(['cost_price' => 0]);

    ownedSale($costed, 2, 100);
    ownedSale($uncosted, 2, 100);

    $trend = profitAnalytics()->trend($this->filters);
    $selling = array_values(array_filter($trend, fn (array $row): bool => (float) $row['revenue'] > 0));

    expect($selling)->toHaveCount(1)
        ->and($selling[0]['revenue'])->toEqual(200.0)
        ->and($selling[0]['profit'])->toEqual(80.0)
        ->and(array_sum(array_column($trend, 'revenue')))->toEqual(200.0);
});

test('the cost basis averages every recorded receipt by quantity', function () {
    $product = analyticsProduct(['cost_price' => 0]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 100,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 60,
    ]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 300,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 80,
    ]);

    $costs = new CostBasis;
    $costs->prime([$product->id]);

    expect($costs->costPerBase($product))->toEqual(75.0)
        ->and($costs->isUsingFallback($product->id))->toBeFalse();
});

test('a product with no costing history falls back to its current cost price', function () {
    $product = analyticsProduct(['cost_price' => 55]);

    $costs = new CostBasis;
    $costs->prime([$product->id]);

    expect($costs->costPerBase($product))->toEqual(55.0)
        ->and($costs->isUsingFallback($product->id))->toBeTrue();
});

test('the day of week breakdown covers every day including Sunday', function () {
    $byDay = salesAnalytics()->byDayOfWeek($this->filters);

    expect($byDay)->toHaveCount(7)
        ->and(array_column($byDay, 'label'))
        ->toEqual(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
});

test('the trend includes every day in the period even when nothing sold', function () {
    ownedSale(analyticsProduct(), 1, 100);

    $trend = salesAnalytics()->trend($this->filters);

    expect($trend)->toHaveCount(now()->daysInMonth);
});

test('the profit trend costs each product in a shared bucket separately', function () {
    $cheap = analyticsProduct(['cost_price' => 10]);
    $pricey = analyticsProduct(['cost_price' => 50]);

    ownedSale($cheap, 1, 100);
    ownedSale($pricey, 1, 100);

    $trend = profitAnalytics()->trend($this->filters);
    $selling = array_values(array_filter($trend, fn (array $row): bool => (float) $row['revenue'] > 0));

    expect($selling)->toHaveCount(1)
        ->and($selling[0]['revenue'])->toEqual(200.0)
        ->and($selling[0]['profit'])->toEqual(140.0);
});

test('inventory activity keeps receipts and issues apart within a bucket', function () {
    $product = analyticsProduct();

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 40,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 60,
        'created_at' => now(),
    ]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 7,
        'unit_id' => $product->base_unit_id,
        'created_at' => now(),
    ]);

    $trend = app(InventoryAnalytics::class)->activityTrend($this->filters);
    $active = array_values(array_filter($trend, fn (array $row): bool => $row['in'] > 0 || $row['out'] > 0));

    expect($active)->toHaveCount(1)
        ->and($active[0]['in'])->toEqual(40.0)
        ->and($active[0]['out'])->toEqual(7.0);
});

test('an unknown filter value is ignored rather than reaching a query', function () {
    $ownedSale = analyticsProduct();
    ownedSale($ownedSale, 1, 100);

    $filters = analyticsFilters([
        'category' => 'not-a-category',
        'product_id' => '0',
        'payment_method' => 'barter',
    ]);

    expect($filters->category)->toBeNull()
        ->and($filters->productId)->toBeNull()
        ->and($filters->paymentMethod)->toBeNull()
        ->and(salesAnalytics()->summary($filters)['owned_revenue'])->toEqual(100.0);
});

test('the product report counts a fully returned product as unsold', function () {
    $product = analyticsProduct();
    $sale = ownedSale($product, 2, 100);
    refundFor($sale, $product, 2, 100);

    $summary = app(ProductPerformanceAnalytics::class)->summary($this->filters);

    expect($summary['sold_products'])->toEqual(0)
        ->and($summary['unsold_products'])->toEqual(1)
        ->and($summary['revenue'])->toEqual(0.0);
});

test('stock that never sold still counts towards unsold products and dead stock value', function () {
    $selling = analyticsProduct(['name' => 'Selling', 'stock' => 10]);
    analyticsProduct(['name' => 'Never sold', 'stock' => 4, 'cost_price' => 25]);

    ownedSale($selling, 2, 100);

    $analytics = app(ProductPerformanceAnalytics::class);
    $summary = $analytics->summary($this->filters);

    expect($summary['sold_products'])->toEqual(1)
        ->and($summary['unsold_products'])->toEqual(1)
        ->and($summary['dead_stock_value'])->toEqual(100.0)
        ->and(collect($analytics->rows($this->filters))->pluck('name')->all())
        ->toContain('Never sold');
});

test('the product rows can be ordered by a column with unknown values sorted as zero', function () {
    analyticsProduct(['name' => 'Alpha', 'stock' => 5, 'cost_price' => 0]);
    analyticsProduct(['name' => 'Beta', 'stock' => 3, 'cost_price' => 50]);

    $analytics = app(ProductPerformanceAnalytics::class);

    expect(array_column($analytics->sortedRows($this->filters, 'stock_value', 'desc'), 'name'))
        ->toEqual(['Beta', 'Alpha'])
        ->and(array_column($analytics->sortedRows($this->filters, 'stock_value', 'asc'), 'name'))
        ->toEqual(['Alpha', 'Beta']);
});

test('an unknown sort column falls back to revenue instead of erroring', function () {
    ownedSale(analyticsProduct(['name' => 'Small sale']), 1, 100);
    ownedSale(analyticsProduct(['name' => 'Big sale']), 3, 100);

    $rows = app(ProductPerformanceAnalytics::class)
        ->sortedRows($this->filters, 'not-a-column', 'sideways');

    expect(array_column($rows, 'name'))->toEqual(['Big sale', 'Small sale']);
});

test('inventory valuation separates cost from retail', function () {
    Product::factory()->create([
        'base_unit_id' => uomUnit('pc')->id,
        'stock' => 10,
        'cost_price' => 60,
        'price' => 100,
    ]);

    $summary = app(InventoryAnalytics::class)->summary();

    expect($summary['stock_value_cost'])->toEqual(600.0)
        ->and($summary['stock_value_retail'])->toEqual(1000.0)
        ->and($summary['potential_margin'])->toEqual(40.0);
});

test('a comparison window keeps the same filters and immediately precedes the period', function () {
    $product = analyticsProduct();
    ownedSale($product, 1, 100);

    $previous = $this->filters->withPeriod(
        AnalyticsPeriod::between(
            now()->startOfMonth()->subDays(now()->daysInMonth),
            now()->startOfMonth()->subSecond(),
        ),
    );

    expect($previous->category)->toEqual($this->filters->category)
        ->and($previous->period->to->toDateString())
        ->toEqual(now()->startOfMonth()->subDay()->toDateString());
});
