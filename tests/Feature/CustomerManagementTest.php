<?php

use App\Models\Customer;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('customers index lists customers', function () {
    $customer = Customer::factory()->create(['name' => 'Amina Yusuf']);

    $response = $this->get(route('customers.index'));

    $response->assertOk();
    $response->assertSee('Amina Yusuf');
});

test('a customer can be created', function () {
    $response = $this->post(route('customers.store'), [
        'name' => 'Thomas Otieno',
        'farm_name' => 'Otieno Horticulture',
        'phone' => '0712345678',
        'location' => 'Kisumu',
        'crop' => 'Tomatoes',
        'hectares' => 4,
        'notes' => null,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('customers', [
        'name' => 'Thomas Otieno',
        'farm_name' => 'Otieno Horticulture',
    ]);
});

test('creating a customer requires a name', function () {
    $response = $this->post(route('customers.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('a customer can be updated', function () {
    $customer = Customer::factory()->create(['name' => 'Old Name']);

    $response = $this->put(route('customers.update', $customer), [
        'name' => 'New Name',
        'farm_name' => $customer->farm_name,
        'phone' => $customer->phone,
        'location' => $customer->location,
        'crop' => $customer->crop,
        'hectares' => $customer->hectares,
        'notes' => $customer->notes,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'New Name']);
});

test('a customer can be deleted', function () {
    $customer = Customer::factory()->create();

    $response = $this->delete(route('customers.destroy', $customer));

    $response->assertRedirect();
    $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
});
