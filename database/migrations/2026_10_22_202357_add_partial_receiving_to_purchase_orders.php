<?php

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
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('received_note')->nullable()->after('note');
            $table->decimal('received_total', 10, 2)->nullable()->after('total');
            $table->foreignId('parent_order_id')->nullable()->after('received_at')->constrained('purchase_orders')->nullOnDelete();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('received_quantity', 10, 2)->nullable()->after('quantity');
        });

        // Orders received before this migration were always received in full.
        DB::table('purchase_orders')
            ->where('status', 'received')
            ->whereNull('received_total')
            ->update(['received_total' => DB::raw('total')]);

        DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', DB::table('purchase_orders')->where('status', 'received')->select('id'))
            ->whereNull('received_quantity')
            ->update(['received_quantity' => DB::raw('quantity')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('received_quantity');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['parent_order_id']);
            $table->dropColumn(['received_note', 'received_total', 'parent_order_id']);
        });
    }
};
