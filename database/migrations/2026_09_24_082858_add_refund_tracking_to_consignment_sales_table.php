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
        Schema::table('consignment_sales', function (Blueprint $table) {
            $table->foreignId('sale_item_id')->nullable()->after('sale_id')->constrained('sale_items')->nullOnDelete();
            $table->decimal('refunded_quantity', 10, 2)->default(0)->after('payable_amount');
            $table->decimal('refunded_line_total', 10, 2)->default(0)->after('refunded_quantity');
            $table->decimal('refunded_payable', 10, 2)->default(0)->after('refunded_line_total');

            $table->index('sale_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consignment_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_item_id');
            $table->dropColumn(['refunded_quantity', 'refunded_line_total', 'refunded_payable']);
        });
    }
};
