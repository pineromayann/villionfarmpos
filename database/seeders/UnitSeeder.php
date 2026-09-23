<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        static::seedCatalog();
    }

    /**
     * Parse the committed UOM catalog into a plain-array shape.
     *
     * @return array<int, array{id: string, name: string, description: string, units: array<int, array{name: string, abbreviation: string}>}>
     */
    public static function catalog(): array
    {
        $json = File::get(database_path('data/uom.json'));
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['unit_types']) || ! is_array($data['unit_types'])) {
            throw new RuntimeException('Invalid UOM catalog JSON.');
        }

        return $data['unit_types'];
    }

    /**
     * Insert the UOM catalog idempotently so migrations, tests, and seeds can all rely on it.
     */
    public static function seedCatalog(): void
    {
        foreach (static::catalog() as $type) {
            DB::table('unit_types')->updateOrInsert(
                ['code' => $type['id']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $typeId = DB::table('unit_types')->where('code', $type['id'])->value('id');

            foreach ($type['units'] as $unit) {
                DB::table('units')->updateOrInsert(
                    [
                        'unit_type_id' => $typeId,
                        'abbreviation' => $unit['abbreviation'],
                    ],
                    [
                        'name' => $unit['name'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
