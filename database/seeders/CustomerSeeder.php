<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customers = [
            ['name' => 'Amina Yusuf', 'farm_name' => 'Green Ridge Farm', 'phone' => '+254 712 445 908', 'location' => 'Nakuru', 'crop' => 'Maize', 'hectares' => 12, 'notes' => 'Prefers deliveries on Fridays.'],
            ['name' => 'Thomas Otieno', 'farm_name' => 'Otieno Horticulture', 'phone' => '+254 733 210 118', 'location' => 'Kisumu', 'crop' => 'Tomatoes', 'hectares' => 4, 'notes' => null],
            ['name' => 'Rosa Mwangi', 'farm_name' => 'Mwangi Estate', 'phone' => '+254 720 887 004', 'location' => 'Nyeri', 'crop' => 'Coffee', 'hectares' => 22, 'notes' => null],
        ];

        foreach ($customers as $customer) {
            Customer::create($customer);
        }
    }
}
