<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->manager = User::factory()->create(['role' => 'manager']);
    $this->cashier = User::factory()->create(['role' => 'cashier']);
    $this->inventoryStaff = User::factory()->create(['role' => 'inventory']);
});

test('analytics pages require authentication', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with([
    'analytics.index',
    'analytics.sales',
    'analytics.gross-profit',
    'analytics.inventory',
    'analytics.procurement',
    'analytics.consignment',
    'analytics.products',
]);

test('a manager can reach every analytics page', function (string $route) {
    $this->actingAs($this->manager)->get(route($route))->assertOk();
})->with([
    'analytics.index',
    'analytics.sales',
    'analytics.gross-profit',
    'analytics.inventory',
    'analytics.procurement',
    'analytics.consignment',
    'analytics.products',
]);

test('a cashier is refused the pages outside their remit', function (string $route) {
    $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
})->with([
    'analytics.gross-profit',
    'analytics.inventory',
    'analytics.procurement',
    'analytics.consignment',
    'analytics.products',
]);

test('a cashier can still see sales analytics', function () {
    $this->actingAs($this->cashier)->get(route('analytics.sales'))->assertOk();
});

test('inventory staff are refused margin and procurement analytics', function (string $route) {
    $this->actingAs($this->inventoryStaff)->get(route($route))->assertForbidden();
})->with([
    'analytics.gross-profit',
    'analytics.procurement',
    'analytics.consignment',
]);

test('the analytics pages render real figures rather than placeholders', function (string $route) {
    $product = analyticsProduct(['name' => 'Martelo', 'price' => 100, 'cost_price' => 60]);
    stockReceivedAt($product, 100, 60);
    ownedSale($product, 4, 100);

    $response = $this->actingAs($this->manager)->get(route($route));

    $response->assertOk()
        ->assertDontSee('number_format([');
})->with([
    'analytics.index',
    'analytics.sales',
    'analytics.gross-profit',
    'analytics.inventory',
    'analytics.procurement',
    'analytics.consignment',
    'analytics.products',
]);

test('an empty database renders zeroed reports instead of errors', function (string $route) {
    $this->actingAs($this->manager)->get(route($route))->assertOk();
})->with([
    'analytics.index',
    'analytics.sales',
    'analytics.gross-profit',
    'analytics.inventory',
    'analytics.procurement',
    'analytics.consignment',
    'analytics.products',
]);

test('a sales period that excludes the sale reports nothing', function () {
    $product = analyticsProduct();
    $sale = ownedSale($product, 2, 100, ['created_at' => now()->subMonths(4)]);

    $response = $this->actingAs($this->manager)
        ->get(route('analytics.sales', ['period' => 'this_month']));

    $response->assertOk()
        ->assertViewHas('report', fn (array $report) => $report['summary']['net_sales'] === 0.0);
});

test('a custom period is honoured', function () {
    $product = analyticsProduct();
    ownedSale($product, 1, 100, ['created_at' => now()->subDays(10)]);
    ownedSale($product, 1, 100, ['created_at' => now()->subDays(60)]);

    $response = $this->actingAs($this->manager)->get(route('analytics.sales', [
        'period' => 'custom',
        'date_from' => now()->subDays(20)->toDateString(),
        'date_to' => now()->toDateString(),
    ]));

    $response->assertOk()
        ->assertViewHas('report', fn (array $report) => (int) $report['summary']['transactions'] === 1);
});

test('an inverted custom period falls back to the default instead of erroring', function () {
    $response = $this->actingAs($this->manager)->get(route('analytics.sales', [
        'period' => 'custom',
        'date_from' => now()->toDateString(),
        'date_to' => now()->subMonth()->toDateString(),
    ]));

    $response->assertOk();
});

test('the sales page reflects the products actually sold', function () {
    $product = analyticsProduct(['name' => 'Fupro 100']);
    ownedSale($product, 3, 100);

    $this->actingAs($this->manager)
        ->get(route('analytics.sales'))
        ->assertOk()
        ->assertSee('Fupro 100');
});

test('every analytics csv export requires authentication', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with([
    'analytics.sales.csv',
    'analytics.gross-profit.csv',
    'analytics.inventory.csv',
    'analytics.procurement.csv',
    'analytics.consignment.csv',
    'analytics.products.csv',
]);

test('a manager can download every analytics csv', function (string $route) {
    $product = analyticsProduct(['name' => 'Bond', 'price' => 100, 'cost_price' => 60]);
    stockReceivedAt($product, 100, 60);
    ownedSale($product, 4, 100);

    $this->actingAs($this->manager)
        ->get(route($route))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8');
})->with([
    'analytics.sales.csv',
    'analytics.gross-profit.csv',
    'analytics.inventory.csv',
    'analytics.procurement.csv',
    'analytics.consignment.csv',
    'analytics.products.csv',
]);

test('a cashier cannot download the csv exports outside their remit', function (string $route) {
    $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
})->with([
    'analytics.gross-profit.csv',
    'analytics.inventory.csv',
    'analytics.procurement.csv',
    'analytics.consignment.csv',
    'analytics.products.csv',
]);

test('a cashier can still download the sales analytics csv', function () {
    $product = analyticsProduct();
    ownedSale($product, 1, 100);

    $this->actingAs($this->cashier)
        ->get(route('analytics.sales.csv'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8');
});

test('the product performance csv carries the product rows and a header', function () {
    $product = analyticsProduct(['name' => 'Alkaline Cleaner', 'price' => 100, 'cost_price' => 60]);
    stockReceivedAt($product, 50, 60);
    ownedSale($product, 2, 100);

    $response = $this->actingAs($this->manager)->get(route('analytics.products.csv'));

    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Alkaline Cleaner')
        ->and($csv)->toContain('Units sold')
        ->and($csv)->toContain('Margin %');
});

test('the sales csv reflects the requested period only', function () {
    $product = analyticsProduct();
    ownedSale($product, 1, 100);
    ownedSale($product, 1, 100, ['created_at' => now()->subMonths(4)]);

    $response = $this->actingAs($this->manager)
        ->get(route('analytics.sales.csv', ['period' => 'this_month']));

    $response->assertOk();

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $rows = array_map(str_getcsv(...), $lines);

    expect($rows[0])->toBe(['Bucket', 'Label', 'Net revenue', 'Transactions'])
        ->and(array_values(array_filter($rows, fn (array $row): bool => (float) ($row[2] ?? 0) > 0)))->toHaveCount(1);
});

test('the sales page explains itself when consigned lines were sold', function () {
    $product = analyticsProduct();
    mixedConsignedSale($product, 2, 3, 100);

    $this->actingAs($this->manager)
        ->get(route('analytics.sales'))
        ->assertOk()
        ->assertSee('carried consigned lines');

    $this->actingAs($this->manager)
        ->get(route('analytics.products'))
        ->assertOk()
        ->assertDontSee('carried consigned lines');
});

test('the product table sorts and pages', function () {
    foreach (range(1, 30) as $index) {
        Product::factory()->create([
            'base_unit_id' => uomUnit('pc')->id,
            'name' => 'Paged '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'stock' => $index,
            'cost_price' => 10,
            'price' => 100,
        ]);
    }

    $this->actingAs($this->manager)
        ->get(route('analytics.products', ['sort' => 'stock_value', 'direction' => 'desc']))
        ->assertOk()
        ->assertViewHas('products', fn ($products) => $products->total() === 30
            && $products->count() === 25
            && $products->currentPage() === 1
            && $products->items()[0]['name'] === 'Paged 30');

    $this->actingAs($this->manager)
        ->get(route('analytics.products', ['sort' => 'stock_value', 'direction' => 'desc', 'page' => 2]))
        ->assertOk()
        ->assertViewHas('products', fn ($products) => $products->currentPage() === 2
            && $products->count() === 5
            && $products->items()[0]['name'] === 'Paged 05');

    $this->actingAs($this->manager)
        ->get(route('analytics.products', ['sort' => 'stock_on_hand', 'direction' => 'asc']))
        ->assertOk()
        ->assertViewHas('products', fn ($products) => $products->items()[0]['name'] === 'Paged 01');

    $this->actingAs($this->manager)
        ->get(route('analytics.products', ['sort' => 'not-a-column', 'direction' => 'sideways']))
        ->assertOk()
        ->assertViewHas('sort', 'revenue')
        ->assertViewHas('direction', 'desc');
});

test('the donut total is sized to stay inside the ring', function () {
    $product = analyticsProduct(['name' => 'Fupro 100', 'price' => 100, 'cost_price' => 60]);
    stockReceivedAt($product, 100, 60);
    ownedSale($product, 4, 100);

    $html = $this->actingAs($this->manager)
        ->get(route('analytics.inventory'))
        ->assertOk()
        ->getContent();

    // The centre block has to be narrower than the ring hole (stroke 12 of a
    // radius 42 circle), or the total overlaps a slice.
    expect($html)->toContain('w-[60%] text-center leading-tight')
        ->and($html)->toContain('stroke-width="12"')
        ->and($html)->not->toContain('stroke-width="14"');
});

test('the bar charts size themselves responsively', function (string $route) {
    $product = analyticsProduct(['name' => 'Fupro 100', 'price' => 100, 'cost_price' => 60]);
    stockReceivedAt($product, 100, 60);
    ownedSale($product, 4, 100);

    $html = $this->actingAs($this->manager)
        ->get(route($route))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('h-36 items-stretch gap-px sm:h-44')
        ->and($html)->not->toContain('style="height: 180px"');
})->with(['analytics.sales', 'analytics.gross-profit']);
