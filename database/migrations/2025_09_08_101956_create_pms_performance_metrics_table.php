<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pms_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->json('metrics_data')->nullable();
            $table->enum('source_type', ['employee', 'supervisor'])->default('employee');
            $table->integer('overall_percentage')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pms_performance_metrics');
    }
};
