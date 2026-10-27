<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('suppliers index lists suppliers', function () {
    Supplier::factory()->create(['name' => 'FarmChem Distributors']);

    $response = $this->get(route('suppliers.index'));

    $response->assertOk();
    $response->assertSee('FarmChem Distributors');
});

test('a supplier can be created', function () {
    $response = $this->post(route('suppliers.store'), [
        'name' => 'FarmChem Distributors',
        'contact_person' => 'Rina',
        'phone' => '0917-000-0000',
        'location' => 'Iloilo',
        'note' => 'Pays in 30 days',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('suppliers', ['name' => 'FarmChem Distributors']);
});

test('creating a supplier requires a name', function () {
    $response = $this->post(route('suppliers.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('a supplier can be updated', function () {
    $supplier = Supplier::factory()->create();

    $response = $this->put(route('suppliers.update', $supplier), [
        'name' => 'New Suppliers Inc.',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'New Suppliers Inc.']);
});

test('a supplier can be deleted', function () {
    $supplier = Supplier::factory()->create();

    $response = $this->delete(route('suppliers.destroy', $supplier));

    $response->assertRedirect();
    $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
});

test('a supplier row shows purchase order count and value', function () {
    $supplier = Supplier::factory()->create();
    PurchaseOrder::factory()->count(2)->create(['supplier_id' => $supplier->id, 'total' => 100]);
    PurchaseOrder::factory()->cancelled()->create(['supplier_id' => $supplier->id, 'total' => 500]);

    $response = $this->get(route('suppliers.index'));

    $response->assertOk();
    $response->assertSee('Purchase orders');
    $response->assertSee('200.00');
    $response->assertDontSee('700.00');
});

test('suppliers are listed in a table with their details', function () {
    Supplier::factory()->create([
        'name' => 'FarmChem Distributors',
        'contact_person' => 'Rina Santos',
        'phone' => '0917-000-0000',
        'location' => 'Iloilo',
    ]);

    $response = $this->get(route('suppliers.index'));

    $response->assertOk();
    $response->assertSee('<table', false);
    $response->assertSee('Supplier</th>', false);
    $response->assertSee('Contact</th>', false);
    $response->assertSee('Phone</th>', false);
    $response->assertSee('Location</th>', false);
    $response->assertSee('Deliveries</th>', false);
    $response->assertSee('Purchase orders</th>', false);
    $response->assertSee('Order value</th>', false);
    $response->assertSee('FarmChem Distributors');
    $response->assertSee('Rina Santos');
    $response->assertSee('0917-000-0000');
    $response->assertSee('Iloilo');
    $response->assertSee('x-data="supplierTable(', false);
});

test('the supplier table can be searched', function () {
    Supplier::factory()->create(['name' => 'FarmChem Distributors']);
    Supplier::factory()->create(['name' => 'Green Harvest', 'contact_person' => 'Liza']);

    $response = $this->get(route('suppliers.index'));

    $response->assertOk();
    $response->assertSee('@supplier-search.window="search = $event.detail"', false);
    $response->assertSee('No suppliers match your search.', false);
});
