<?php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the consignment partners page lists partners and their balances', function () {
    $partner = consignmentPartner();
    $product = consignmentProduct();

    $consignment = $partner->consignments()->create(['received_at' => today()]);
    $consignment->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'unit_id' => $product->base_unit_id,
        'unit_cost' => 5,
        'line_total' => 50,
    ]);

    $this->get(route('consignment-partners.index'))
        ->assertOk()
        ->assertSee($partner->name)
        ->assertSee('Balance due to partners');
});

test('a consignment partner can be added', function () {
    $this->post(route('consignment-partners.store'), [
        'name' => 'AgriGrow Distributors',
        'contact_person' => 'Mario',
        'phone' => '0917',
        'location' => 'Bayombong',
        'note' => null,
    ])->assertRedirect();

    $this->assertDatabaseHas('consignment_partners', ['name' => 'AgriGrow Distributors']);
});

test('a consignment partner requires a name', function () {
    $this->post(route('consignment-partners.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->assertDatabaseCount('consignment_partners', 0);
});

test('a consignment partner can be updated', function () {
    $partner = consignmentPartner();

    $this->put(route('consignment-partners.update', $partner), [
        'name' => 'Updated Name',
    ])->assertRedirect();

    expect($partner->fresh()->name)->toBe('Updated Name');
});

test('a consignment partner can be removed', function () {
    $partner = consignmentPartner();

    $this->delete(route('consignment-partners.destroy', $partner))->assertRedirect();

    $this->assertDatabaseMissing('consignment_partners', ['id' => $partner->id]);
});
