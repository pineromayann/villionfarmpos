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
        foreach (glob(base_path('dbjsons/*_products.json')) as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            foreach ($data['products'] as $item) {
                Product::updateOrCreate(
                    ['name' => $item['product_name']],
                    $this->productData($data['category'], $item)
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function productData(string $category, array $item): array
    {
        $costPrice = $this->costPrice($item);
        $dealerPrice = $this->nullableDecimal($item['dealers_price_cod'] ?? null);
        $termsPrice = $this->nullableDecimal($item['terms_30_days'] ?? null);

        return [
            'category' => $category,
            'cost_price' => $costPrice,
            'dealers_price_cod' => $dealerPrice,
            'terms_30_days' => $termsPrice,
            'expiry_date' => $item['expiration_date'] ?? null,
            'price' => $dealerPrice ?? $termsPrice ?? $costPrice,
            'stock' => $this->nullableDecimal($item['stock_available'] ?? null) ?? 0,
            'unit' => $this->unitFor($item['product_name']),
            'note' => $item['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function costPrice(array $item): ?float
    {
        $costPrice = $item['cost_price'] ?? null;

        if (is_int($costPrice) || is_float($costPrice)) {
            return (float) $costPrice;
        }

        return null;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function unitFor(string $name): string
    {
        $lower = strtolower($name);

        if (str_contains($lower, 'gal')) {
            return 'GAL';
        }

        if (str_contains($lower, 'ml')) {
            return 'ml';
        }

        if (preg_match('/\d+\s*(g|kg)\b/', $lower)) {
            return 'kg';
        }

        return 'L';
    }
}
