<?php

use App\Models\ConsignmentSale;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the consignment sales page lists sold consigned items', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    ConsignmentSale::factory()->create([
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'payable_amount' => 25,
        'line_total' => 30,
        'sold_at' => now(),
    ]);

    $this->get(route('consignment.sales'))
        ->assertOk()
        ->assertSee($partner->name)
        ->assertSee($product->name)
        ->assertSee('25.00')
        ->assertSee('30.00');
});

test('consignment sales can be filtered by partner', function () {
    $partnerA = consignmentPartner();
    $partnerB = consignmentPartner();
    $productA = consignmentProduct(['name' => 'KARATE CONS 500ml']);
    $productB = consignmentProduct(['name' => 'BULLSEYE CONS 1L']);

    ConsignmentSale::factory()->create(['partner_id' => $partnerA->id, 'product_id' => $productA->id, 'sold_at' => now()]);
    ConsignmentSale::factory()->create(['partner_id' => $partnerB->id, 'product_id' => $productB->id, 'sold_at' => now()]);

    $this->get(route('consignment.sales', ['partner_id' => $partnerA->id]))
        ->assertOk()
        ->assertSee($productA->name)
        ->assertDontSee($productB->name);
});
