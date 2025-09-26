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
        Schema::create('kpi_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_name'); // Make task_name unique
            $table->text('description')->nullable();
            $table->softDeletes(); // Add soft delete capability
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_tasks');
    }
};
