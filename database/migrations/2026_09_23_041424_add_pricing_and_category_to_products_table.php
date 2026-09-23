<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category')->nullable()->index();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('dealers_price_cod', 10, 2)->nullable();
            $table->decimal('terms_30_days', 10, 2)->nullable();
            $table->text('note')->nullable();
            $table->decimal('price', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable(false)->change();
            $table->dropColumn(['category', 'cost_price', 'dealers_price_cod', 'terms_30_days', 'note']);
        });
    }
};
