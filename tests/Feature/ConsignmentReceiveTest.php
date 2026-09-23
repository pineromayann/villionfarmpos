<?php

use App\Models\Consignment;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the receive consignment page renders', function () {
    consignmentPartner();

    $this->get(route('consignment.receive'))
        ->assertOk()
        ->assertSee('Receive Consignment')
        ->assertSee('Balance due');
});

test('goods can be received from a partner at the consignment price', function () {
    $partner = consignmentPartner();
    $product = uomProductWithDozen();
    $dozen = uomUnit('dz');

    $this->post(route('consignment.receive.store'), [
        'partner_id' => $partner->id,
        'received_at' => today()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_id' => $dozen->id, 'unit_cost' => 60],
        ],
    ])->assertRedirect();

    $consignment = Consignment::firstOrFail();

    $this->assertDatabaseHas('consignments', ['id' => $consignment->id, 'partner_id' => $partner->id]);

    $this->assertDatabaseHas('consignment_items', [
        'consignment_id' => $consignment->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_id' => $dozen->id,
        'unit_cost' => 60,
        'line_total' => 120,
    ]);
});

test('a consignment requires a line item', function () {
    $partner = consignmentPartner();

    $this->post(route('consignment.receive.store'), [
        'partner_id' => $partner->id,
        'received_at' => today()->toDateString(),
        'items' => [],
    ])->assertSessionHasErrors('items');

    expect(Consignment::count())->toBe(0);
});

test('a consignment rejects a unit the product does not sell in', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    $this->post(route('consignment.receive.store'), [
        'partner_id' => $partner->id,
        'received_at' => today()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_id' => uomUnit('L')->id, 'unit_cost' => 5],
        ],
    ])->assertSessionHasErrors('items.0.unit_id');

    expect(Consignment::count())->toBe(0);
});
