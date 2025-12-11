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
        Schema::create('shift_overtime_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->onDelete('cascade');
            $table->decimal('shift_hours_per_day', 4, 2)->default(8.00);
            $table->decimal('working_days_per_month', 4, 2)->default(30.00);
            $table->decimal('ot_multiplier', 4, 2)->default(1.50);
            $table->decimal('holiday_multiplier', 4, 2)->default(2.00);
            $table->json('ignore_hours_threshold')->nullable()->comment('Ignore threshold as JSON. Example: {"minutes":60} or {"hours":1} or {"minutes":30,"hours":1}');
            $table->softDeletes();
            $table->timestamps();
            
            $table->index('shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_overtime_rates');
    }
};
