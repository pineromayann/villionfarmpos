<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            ['name' => 'Actellic 50 EC', 'active_ingredient' => 'Pirimiphos-methyl 50%', 'batch_number' => 'ACT-2426', 'expiry_date' => now()->addYears(1), 'price' => 10.50, 'stock' => 30, 'unit' => 'L'],
            ['name' => 'Karate 2.5 WG', 'active_ingredient' => 'Lambda-cyhalothrin 2.5%', 'batch_number' => 'KRT-1189', 'expiry_date' => now()->addMonths(3), 'price' => 18.00, 'stock' => 6, 'unit' => 'kg'],
            ['name' => 'Confidor 200 SL', 'active_ingredient' => 'Imidacloprid 200 g/L', 'batch_number' => 'CNF-3402', 'expiry_date' => now()->addWeeks(2), 'price' => 32.75, 'stock' => 24, 'unit' => 'L'],
            ['name' => 'Match 050 EC', 'active_ingredient' => 'Lufenuron 50 g/L', 'batch_number' => 'MTC-0921', 'expiry_date' => now()->addMonths(4), 'price' => 28.90, 'stock' => 3, 'unit' => 'L'],
            ['name' => 'Belt 480 SC', 'active_ingredient' => 'Flubendiamide 480 g/L', 'batch_number' => 'BLT-7710', 'expiry_date' => now()->addMonths(5), 'price' => 45.00, 'stock' => 14, 'unit' => 'L'],
            ['name' => 'Decis 25 EC', 'active_ingredient' => 'Deltamethrin 25 g/L', 'batch_number' => 'DCS-5583', 'expiry_date' => now()->addYears(2), 'price' => 14.25, 'stock' => 60, 'unit' => 'L'],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
