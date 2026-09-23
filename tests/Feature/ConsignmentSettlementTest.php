<?php

use App\Models\ConsignmentSale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a payment reduces the balance due shown on the settlement page', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    ConsignmentSale::factory()->create([
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'payable_amount' => 100,
    ]);

    $this->get(route('consignment.settlement'))
        ->assertOk()
        ->assertSee('100.00');

    $this->post(route('consignment.settlement.store'), [
        'partner_id' => $partner->id,
        'amount' => 40,
        'settled_at' => today()->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('consignment_settlements', [
        'partner_id' => $partner->id,
        'amount' => 40,
    ]);

    $this->get(route('consignment.settlement'))
        ->assertSee('60.00');
});

test('a settlement requires a positive amount', function () {
    $partner = consignmentPartner();

    $this->post(route('consignment.settlement.store'), [
        'partner_id' => $partner->id,
        'amount' => 0,
        'settled_at' => today()->toDateString(),
    ])->assertSessionHasErrors('amount');

    $this->assertDatabaseCount('consignment_settlements', 0);
});
