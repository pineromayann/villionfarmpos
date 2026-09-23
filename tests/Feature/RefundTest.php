<?php

use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the refunds page renders', function () {
    $response = $this->get(route('refunds.index'));

    $response->assertOk();
    $response->assertSee('New refund');
});

test('a sale returns its returnable items as json', function () {
    $sale = Sale::factory()->create();
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'product_id' => Product::factory()->create(['name' => 'VirtaKo']),
        'quantity' => 3,
        'unit_price' => 10,
        'line_total' => 30,
    ]);

    $response = $this->getJson(route('refunds.sale-items', $sale));

    $response->assertOk();
    $response->assertJson([
        ['id' => $item->id, 'product' => 'VirtaKo', 'remaining' => 3, 'unit_price' => 10],
    ]);
});

test('a sale item already refunded reports a reduced remaining quantity', function () {
    $sale = Sale::factory()->create();
    $product = Product::factory()->create();
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);
    Refund::factory()->create([
        'sale_id' => $sale->id,
        'sale_item_id' => $item->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $response = $this->getJson(route('refunds.sale-items', $sale));

    $response->assertOk();
    $response->assertJson([['remaining' => 2]]);
});

test('a refund restocks the product and records an in movement', function () {
    $product = Product::factory()->create(['stock' => 5]);
    $sale = Sale::factory()->create();
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 4,
        'unit_price' => 50,
        'line_total' => 200,
    ]);

    $response = $this->post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $item->id, 'quantity' => 2],
        ],
        'note' => 'Damaged container',
    ]);

    $response->assertRedirect(route('refunds.index'));
    $this->assertDatabaseHas('refunds', [
        'sale_id' => $sale->id,
        'sale_item_id' => $item->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 50,
        'line_total' => 100,
        'note' => 'Damaged container',
    ]);
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 2,
        'reason' => 'Return',
        'ref_type' => 'sale',
        'ref_id' => $sale->id,
    ]);
    expect($product->fresh()->stock)->toEqual(7);
});

test('a refund cannot exceed the returnable quantity', function () {
    $product = Product::factory()->create(['stock' => 5]);
    $sale = Sale::factory()->create();
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $response = $this->post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $item->id, 'quantity' => 4],
        ],
    ]);

    $response->assertSessionHasErrors('items.0.quantity');
    $this->assertDatabaseMissing('refunds', ['sale_item_id' => $item->id]);
    expect($product->fresh()->stock)->toEqual(5);
});

test('a refund requires at least one quantity', function () {
    $product = Product::factory()->create(['stock' => 5]);
    $sale = Sale::factory()->create();
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $response = $this->post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $item->id, 'quantity' => 0],
        ],
    ]);

    $response->assertSessionHasErrors('items.quantity');
    $this->assertDatabaseMissing('refunds', ['sale_item_id' => $item->id]);
    expect($product->fresh()->stock)->toEqual(5);
});

test('a refund ignores items that were left without a quantity', function () {
    $product = Product::factory()->create(['stock' => 5]);
    $sale = Sale::factory()->create();
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $response = $this->post(route('refunds.store'), [
        'items' => [
            ['sale_item_id' => $item->id, 'quantity' => 2],
            ['sale_item_id' => $item->id, 'quantity' => 0],
        ],
    ]);

    $response->assertRedirect(route('refunds.index'));
    $this->assertDatabaseHas('refunds', ['sale_item_id' => $item->id, 'quantity' => 2]);
    expect(Refund::count())->toBe(1);
});

test('a refund requires at least one item', function () {
    $response = $this->post(route('refunds.store'), ['items' => []]);

    $response->assertSessionHasErrors('items');
});
