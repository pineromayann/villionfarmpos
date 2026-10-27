<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reorder level is the stock level at or below which a product is
     * suggested for procurement. It is expressed in the product's base unit,
     * the same unit `products.stock` is kept in.
     *
     * A null level means "use the application default"
     * (Product::LOW_STOCK_THRESHOLD), so existing products keep their current
     * behaviour without a backfill.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reorder_level', 10, 2)->nullable()->after('stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });
    }
};
