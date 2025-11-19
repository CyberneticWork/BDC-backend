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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('accountNumber');
            $table->string('accountName');
            $table->string('accountType');
            $table->string('accountSubCategory');
         $table->string('accountGroup')->nullable();
            // $table->foreignId('accountGroup')->constrained('account_groups')->onDelete('cascade');
            $table->decimal('openingBalance', 15, 2);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
