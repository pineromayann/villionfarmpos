<?php

use Database\Seeders\UnitSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('abbreviation', 20);
            $table->timestamps();
            $table->unique(['unit_type_id', 'abbreviation']);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('conversion_to_base', 12, 4);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('base_unit_id')->nullable()->after('stock')->constrained('units')->restrictOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('quantity')->constrained('units')->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('quantity')->constrained('units')->nullOnDelete();
        });

        UnitSeeder::seedCatalog();

        $unitIds = DB::table('units')->pluck('id', 'abbreviation');
        $map = ['L' => 'L', 'ml' => 'mL', 'GAL' => 'gal', 'kg' => 'kg'];

        foreach (DB::table('products')->get() as $product) {
            if ($product->base_unit_id !== null) {
                continue;
            }

            $unitId = $unitIds[$map[$product->unit] ?? $product->unit] ?? null;

            if ($unitId === null) {
                continue;
            }

            DB::table('products')->where('id', $product->id)->update(['base_unit_id' => $unitId]);

            DB::table('product_units')->updateOrInsert(
                [
                    'product_id' => $product->id,
                    'unit_id' => $unitId,
                ],
                [
                    'conversion_to_base' => 1,
                    'is_base' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit', 10)->default('L')->after('stock');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_unit_id');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });

        Schema::dropIfExists('product_units');
        Schema::dropIfExists('units');
        Schema::dropIfExists('unit_types');
    }
};
