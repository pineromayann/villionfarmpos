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
        Schema::table('sales', function (Blueprint $table) {
            $table->string('receipt_number', 20)->nullable()->after('id');
        });

        // Backfill so every sale carries a receipt number from day one.
        DB::table('sales')->orderBy('id')->chunkById(200, function ($sales) {
            foreach ($sales as $sale) {
                DB::table('sales')
                    ->where('id', $sale->id)
                    ->update(['receipt_number' => 'ORN-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT)]);
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unique('receipt_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
