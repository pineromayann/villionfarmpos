<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the stock movements page lists movements and net change', function () {
    $product = Product::factory()->create(['name' => 'Karate 2.5 WG']);
    StockMovement::factory()->incoming()->create([
        'product_id' => $product->id,
        'quantity' => 5,
        'reason' => 'Stock in',
    ]);
    StockMovement::factory()->outgoing()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'reason' => 'Sale',
        'ref_type' => 'sale',
        'ref_id' => 10,
    ]);

    $response = $this->get(route('stock.movements.index'));

    $response->assertOk();
    $response->assertSee('Karate 2.5 WG');
    $response->assertSee('3.00');
});

test('stock in increases stock and records a movement', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $supplier = Supplier::factory()->create();

    $response = $this->post(route('stock.in.store'), [
        'product_id' => $product->id,
        'quantity' => 4,
        'unit_cost' => 55.50,
        'supplier_id' => $supplier->id,
        'reason' => 'New delivery',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 4,
        'unit_cost' => 55.50,
        'supplier_id' => $supplier->id,
        'reason' => 'New delivery',
    ]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 14]);
});

test('stock in requires a positive quantity', function () {
    $product = Product::factory()->create(['stock' => 5]);

    $response = $this->post(route('stock.in.store'), [
        'product_id' => $product->id,
        'quantity' => 0,
    ]);

    $response->assertSessionHasErrors('quantity');
    $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
});

test('stock out decreases stock and records a movement', function () {
    $product = Product::factory()->create(['stock' => 10]);

    $response = $this->post(route('stock.out.store'), [
        'product_id' => $product->id,
        'quantity' => 3,
        'reason' => 'damaged',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 3,
        'reason' => 'damaged',
    ]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 7]);
});

test('stock out is rejected when it exceeds available stock', function () {
    $product = Product::factory()->create(['stock' => 2]);

    $response = $this->post(route('stock.out.store'), [
        'product_id' => $product->id,
        'quantity' => 5,
        'reason' => 'lost',
    ]);

    $response->assertSessionHasErrors('quantity');
    $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
    expect($product->fresh()->stock)->toEqual(2);
});

test('stock out requires a valid reason', function () {
    $product = Product::factory()->create(['stock' => 5]);

    $response = $this->post(route('stock.out.store'), [
        'product_id' => $product->id,
        'quantity' => 1,
        'reason' => 'gave a sample',
    ]);

    $response->assertSessionHasErrors('reason');
    $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
});
