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
        // If 'suppliers' already exists, nothing to do
        if (Schema::hasTable('suppliers')) {
            return;
        }

        // If legacy singular table exists, rename it to plural
        if (Schema::hasTable('supplier')) {
            Schema::rename('supplier', 'suppliers');
            return;
        }

        // Otherwise create the plural table
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name');
            $table->string('phone_number')->nullable();
            $table->string('nic')->nullable();
            $table->string('email')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->decimal('credit_value', 15, 2)->default(0);
            $table->integer('credit_period')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // If the singular table already exists, do nothing
        if (Schema::hasTable('supplier')) {
            return;
        }

        if (Schema::hasTable('suppliers')) {
            // Try to rename back to singular; if singular exists, drop plural
            if (!Schema::hasTable('supplier')) {
                Schema::rename('suppliers', 'supplier');
            } else {
                Schema::dropIfExists('suppliers');
            }
        }
    }
};
