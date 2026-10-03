<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the edit product dialog caps its height so the save action stays on screen', function () {
    $product = Product::factory()->create();

    $response = $this->get(route('inventory.index'));

    $response->assertOk();
    $response->assertSee('max-h-full', false);
    $response->assertSee('id="product-edit-form-'.$product->id.'"', false);
    $response->assertSee('form="product-edit-form-'.$product->id.'"', false);
    $response->assertSee('Save changes');
});

test('the add product dialog caps its height so the submit action stays on screen', function () {
    $response = $this->get(route('inventory.index'));

    $response->assertOk();
    $response->assertSee('max-h-full', false);
    $response->assertSee('id="product-create-form"', false);
    $response->assertSee('form="product-create-form"', false);
});

test('dialogs dismiss only from the backdrop so their fields cannot close them', function () {
    Product::factory()->create();

    $response = $this->get(route('stock.movements.index'));

    $response->assertOk();
    $response->assertSee('@click.self="stockIn = false"', false);
    $response->assertSee('@click.self="stockOut = false"', false);
    $response->assertDontSee('click.outside', false);
});

test('each dialog binds to a state its own alpine scope declares', function () {
    Product::factory()->create();

    $response = $this->get(route('stock.movements.index'));

    $response->assertOk();
    $response->assertSee('@click="stockIn = true"', false);
    $response->assertSee('x-show="stockIn"', false);
    $response->assertDontSee('x-show="open"', false);
    $response->assertDontSee('@click.self="open = false"', false);
});

test('timestamps reflect the application timezone when actions are recorded', function () {
    $this->travelTo('2026-10-03 11:30:00');

    $product = Product::factory()->create(['stock' => 5]);

    $response = $this->post(route('stock.in.store'), [
        'product_id' => $product->id,
        'quantity' => 2,
        'reason' => 'restock',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('stock_movements', [
        'quantity' => 2,
        'type' => 'in',
        'created_at' => '2026-10-03 11:30:00',
    ]);
    $movement = \App\Models\StockMovement::latest()->first();
    expect($movement->created_at->format('Y-m-d H:i:s'))->toBe('2026-10-03 11:30:00');
});
