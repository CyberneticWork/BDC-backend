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
        Schema::create('employee_wise_allowances', function (Blueprint $table) {
            $table->id();

            $table->string('allowance_code')->unique();
            $table->string('allowance_name');
            $table->text('allowance_description')->nullable();

            $table
                ->foreignId('employee_id')
                ->constrained()
                ->onDelete('cascade');

            $table->decimal('amount', 10, 2);

            $table->date('date');

            $table->string('status', 20)->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_wise_allowance');
    }
};
