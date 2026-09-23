<?php

use App\Models\Consignment;
use App\Models\ConsignmentAdjustment;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('an adjustment only offers products received from the selected partner', function () {
    $partner = consignmentPartner();
    $received = consignmentProduct(['name' => 'Received Product']);
    $notReceived = consignmentProduct(['name' => 'Never Received Product']);

    Consignment::create([
        'partner_id' => $partner->id,
        'received_at' => now(),
        'created_by' => null,
    ])->items()->create([
        'product_id' => $received->id,
        'quantity' => 10,
        'unit_id' => uomUnit('pc')->id,
        'unit_cost' => 10,
        'line_total' => 100,
    ]);

    $this->get(route('consignment.stock'))
        ->assertOk()
        ->assertSee($received->name)
        ->assertDontSee($notReceived->name);
});

test('an adjustment for a product received from the partner is recorded in base units', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    Consignment::create([
        'partner_id' => $partner->id,
        'received_at' => now(),
        'created_by' => null,
    ])->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => uomUnit('pc')->id,
        'unit_cost' => 10,
        'line_total' => 100,
    ]);

    $this->post(route('consignment.adjustments.store'), [
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'reason' => 'damaged',
        'adjusted_at' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('consignment_adjustments', [
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'reason' => 'damaged',
    ]);
});

test('an adjustment for a product never received from the selected partner is rejected', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    $this->post(route('consignment.adjustments.store'), [
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'reason' => 'return',
        'adjusted_at' => now()->toDateString(),
    ])->assertSessionHasErrors('product_id');

    expect(ConsignmentAdjustment::count())->toBe(0);
});

test('an adjustment cannot use a product received only from another partner', function () {
    $partnerA = consignmentPartner();
    $partnerB = consignmentPartner();
    $product = consignmentProduct();

    Consignment::create([
        'partner_id' => $partnerA->id,
        'received_at' => now(),
        'created_by' => null,
    ])->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => uomUnit('pc')->id,
        'unit_cost' => 10,
        'line_total' => 100,
    ]);

    $this->post(route('consignment.adjustments.store'), [
        'partner_id' => $partnerB->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'reason' => 'return',
        'adjusted_at' => now()->toDateString(),
    ])->assertSessionHasErrors('product_id');

    expect(ConsignmentAdjustment::count())->toBe(0);
});

test('an adjustment cannot exceed the on-hand quantity of the product', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    Consignment::create([
        'partner_id' => $partner->id,
        'received_at' => now(),
        'created_by' => null,
    ])->items()->create([
        'product_id' => $product->id,
        'quantity' => 5,
        'unit_id' => uomUnit('pc')->id,
        'unit_cost' => 10,
        'line_total' => 50,
    ]);

    $this->post(route('consignment.adjustments.store'), [
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'quantity' => 6,
        'reason' => 'return',
        'adjusted_at' => now()->toDateString(),
    ])->assertSessionHasErrors('quantity');

    expect(ConsignmentAdjustment::count())->toBe(0);
});

test('an adjustment equal to the on-hand quantity is accepted', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    Consignment::create([
        'partner_id' => $partner->id,
        'received_at' => now(),
        'created_by' => null,
    ])->items()->create([
        'product_id' => $product->id,
        'quantity' => 5,
        'unit_id' => uomUnit('pc')->id,
        'unit_cost' => 10,
        'line_total' => 50,
    ]);

    $this->post(route('consignment.adjustments.store'), [
        'partner_id' => $partner->id,
        'product_id' => $product->id,
        'quantity' => 5,
        'reason' => 'return',
        'adjusted_at' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('success');

    expect(ConsignmentAdjustment::count())->toBe(1);
});
