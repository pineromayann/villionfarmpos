<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the reports index page loads', function () {
    $response = $this->get(route('reports.index'));

    $response->assertOk();
    $response->assertSee('Sales report');
    $response->assertSee('Inventory report');
    $response->assertSee('Customers report');
});

test('the sales pdf report can be exported', function () {
    Sale::factory()->create();

    $response = $this->get(route('reports.sales.pdf'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('the sales csv report can be exported and respects the date range filter', function () {
    $inRange = Sale::factory()->create(['total' => 42, 'created_at' => now()]);
    $outOfRange = Sale::factory()->create(['total' => 99, 'created_at' => now()->subYears(2)]);

    $response = $this->get(route('reports.sales.csv', [
        'date_from' => now()->subDay()->toDateString(),
        'date_to' => now()->addDay()->toDateString(),
    ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('42.00');
    expect($csv)->not->toContain('99.00');
});

test('the inventory pdf and csv reports can be exported', function () {
    Product::factory()->create(['name' => 'Actellic 50 EC']);

    $pdf = $this->get(route('reports.inventory.pdf'));
    $pdf->assertOk();
    $pdf->assertHeader('content-type', 'application/pdf');

    $csv = $this->get(route('reports.inventory.csv'));
    $csv->assertOk();
    $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($csv->streamedContent())->toContain('Actellic 50 EC');
});

test('the inventory csv report can be filtered by category', function () {
    Product::factory()->create(['name' => 'Foliar Thing', 'category' => 'foliar']);
    Product::factory()->create(['name' => 'Herb Thing', 'category' => 'herbicide']);

    $response = $this->get(route('reports.inventory.csv', ['category' => 'herbicide']));

    $response->assertOk();
    expect($response->streamedContent())->toContain('Herb Thing');
    expect($response->streamedContent())->not->toContain('Foliar Thing');
});

test('the inventory pdf report can be filtered by category', function () {
    Product::factory()->create(['name' => 'Foliar Thing', 'category' => 'foliar']);

    $response = $this->get(route('reports.inventory.pdf', ['category' => 'herbicide']));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('the sales csv report can be filtered by category', function () {
    $foliar = Product::factory()->create([
        'name' => 'Foliar Big',
        'category' => 'foliar',
        'price' => 10,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
    ]);
    $herbicide = Product::factory()->create([
        'name' => 'Herbicide Big',
        'category' => 'herbicide',
        'price' => 20,
        'cost_price' => null,
        'dealers_price_cod' => null,
        'terms_30_days' => null,
    ]);

    $foliarSale = Sale::factory()->create(['total' => 30, 'created_at' => now()]);
    $foliarSale->items()->create([
        'product_id' => $foliar->id,
        'quantity' => 3,
        'unit_price' => 10,
        'line_total' => 30,
    ]);

    $herbSale = Sale::factory()->create(['total' => 40, 'created_at' => now()]);
    $herbSale->items()->create([
        'product_id' => $herbicide->id,
        'quantity' => 2,
        'unit_price' => 20,
        'line_total' => 40,
    ]);

    $response = $this->get(route('reports.sales.csv', ['category' => 'herbicide']));

    $response->assertOk();
    expect($response->streamedContent())->toContain('40.00');
    expect($response->streamedContent())->not->toContain('30.00');
});

test('a report category filter rejects unknown categories', function () {
    $response = $this->get(route('reports.inventory.csv', ['category' => 'fertilizer']));

    $response->assertSessionHasErrors('category');
});

test('the customers pdf and csv reports can be exported', function () {
    Customer::factory()->create(['name' => 'Amina Yusuf']);

    $pdf = $this->get(route('reports.customers.pdf'));
    $pdf->assertOk();
    $pdf->assertHeader('content-type', 'application/pdf');

    $csv = $this->get(route('reports.customers.csv'));
    $csv->assertOk();
    $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($csv->streamedContent())->toContain('Amina Yusuf');
});
