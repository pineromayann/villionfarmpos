<?php

use App\Models\Consignment;
use App\Models\ConsignmentAdjustment;
use App\Models\ConsignmentSale;
use App\Models\ConsignmentSettlement;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('history merges receipts, sales, adjustments and payments', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    $consignment = Consignment::factory()->create(['partner_id' => $partner->id, 'received_at' => now()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    ConsignmentSale::factory()->create(['partner_id' => $partner->id, 'product_id' => $product->id, 'sold_at' => now()]);
    ConsignmentAdjustment::factory()->create(['partner_id' => $partner->id, 'product_id' => $product->id, 'reason' => 'damaged', 'value' => 10]);
    ConsignmentSettlement::factory()->create(['partner_id' => $partner->id, 'amount' => 20]);

    $this->get(route('consignment.history'))
        ->assertOk()
        ->assertSee('Received')
        ->assertSee('Sold')
        ->assertSee('Adjusted')
        ->assertSee('Payment')
        ->assertSee($product->name);
});

test('history can be filtered by partner', function () {
    $partnerA = consignmentPartner();
    $partnerB = consignmentPartner();
    $product = consignmentProduct(['name' => 'TRACKER CONS 250ml']);

    ConsignmentSale::factory()->create(['partner_id' => $partnerA->id, 'product_id' => $product->id, 'sold_at' => now()]);
    ConsignmentSettlement::factory()->create(['partner_id' => $partnerB->id, 'amount' => 30]);

    $this->get(route('consignment.history', ['partner_id' => $partnerA->id]))
        ->assertOk()
        ->assertSee($product->name)
        ->assertDontSee('Payment made');
});
