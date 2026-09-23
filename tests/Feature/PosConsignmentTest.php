<?php

use App\Models\ConsignmentSale;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the pos page renders consigned products with their partners', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['stock' => 20]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    $this->get(route('pos.index'))
        ->assertOk()
        ->assertSee($product->name);
});

test('selling a consigned item records a consignment sale without touching owned stock', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['stock' => 20]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 3, 'partner_id' => $partner->id],
        ]),
    ])->assertRedirect(route('pos.index'));

    $this->assertDatabaseHas('consignment_sales', [
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit_cost' => 5,
        'payable_amount' => 15,
    ]);

    expect($product->fresh()->stock)->toEqual(20);
    expect(StockMovement::count())->toBe(0);
});

test('a sale can mix owned and consigned lines in one transaction', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['stock' => 10]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 2],
            ['product_id' => $product->id, 'qty' => 4, 'partner_id' => $partner->id],
        ]),
    ])->assertRedirect(route('pos.index'));

    expect($product->fresh()->stock)->toEqual(8);
    expect(Sale::count())->toBe(1);
    expect(StockMovement::count())->toBe(1);
    expect(ConsignmentSale::count())->toBe(1);

    $this->assertDatabaseHas('consignment_sales', [
        'partner_id' => $partner->id,
        'quantity' => 4,
        'payable_amount' => 20,
    ]);
});

test('a consigned sale is rejected when it exceeds the partner on hand', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct(['stock' => 100]);

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 10,
    ]);

    $response = $this->post(route('pos.store'), [
        'payment_method' => 'cash',
        'cart' => json_encode([
            ['product_id' => $product->id, 'qty' => 3, 'partner_id' => $partner->id],
        ]),
    ]);

    $response->assertStatus(422);
    expect(Sale::count())->toBe(0);
    expect(ConsignmentSale::count())->toBe(0);
});
