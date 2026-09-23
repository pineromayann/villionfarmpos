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
        Schema::create('consignment_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('consignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('consignment_partners')->nullOnDelete();
            $table->date('received_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_id', 'received_at']);
        });

        Schema::create('consignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('consignment_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('consignment_partners')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('payable_amount', 10, 2)->default(0);
            $table->timestamp('sold_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('consignment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('consignment_partners')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->string('reason')->default('return');
            $table->decimal('value', 10, 2)->default(0);
            $table->date('adjusted_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('consignment_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('consignment_partners')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('settled_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consignment_settlements');
        Schema::dropIfExists('consignment_adjustments');
        Schema::dropIfExists('consignment_sales');
        Schema::dropIfExists('consignment_items');
        Schema::dropIfExists('consignments');
        Schema::dropIfExists('consignment_partners');
    }
};
