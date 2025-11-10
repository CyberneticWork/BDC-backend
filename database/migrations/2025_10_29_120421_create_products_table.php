<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('barcode');
            $table->string('code');
            $table->decimal('cost', 10, 2);
            $table->integer('quantity')->default(0); //add for quantity column for product quantitiy
            $table->text('description');
            $table->foreignId('discount_level_id')->constrained('discount_levels');
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->decimal('min_price', 10, 2);
            $table->decimal('mrp', 10, 2);
            $table->string('name');
            $table->string('oem_numbers');
            $table->foreignId('product_type_id')->constrained('product_types');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
