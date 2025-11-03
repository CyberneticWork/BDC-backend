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
            $table->float('unitPrice')->default(0);
            $table->integer('quantity')->default(0);
            $table->float('amount')->default(0);
            $table->float('paid_value')->default(0);
            $table->float('discountValue')->default(0);
            $table->string('referNumber')->nullable();
            $table->enum('status', ['pending', 'reject', 'completed'])->default('pending');
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
