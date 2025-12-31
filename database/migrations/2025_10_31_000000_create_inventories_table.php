<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->string('voucherNumber');
            $table->float('amount')->default(0);
            $table->float('paid_value')->default(0);
            $table->float('discountValue')->default(0);
            $table->foreignId('discountLevel_id')->nullable()->constrained('discount_levels')->nullOnDelete();
            $table->string('referNumber')->nullable();
            $table->string('refervoucherNumber')->nullable();
            $table->boolean('is_ref')->default(false);
            $table->boolean('is_confirmed')->default(false);
            $table->enum('status', ['pending', 'reject', 'completed'])->default('pending');
            $table->foreignId('center_id')->nullable()->constrained('centers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('from_center')->nullable()->constrained('centers')->nullOnDelete();
            $table->foreignId('to_center')->nullable()->constrained('centers')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();


        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
