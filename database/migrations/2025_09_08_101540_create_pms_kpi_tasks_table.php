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
        Schema::create('pms_kpi_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'attention', 'inactive'])->default('active');
            $table->enum('completion_status', ['not-started', 'in-progress', 'pending', 'completed'])->default('not-started');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->string('category', 100)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('last_updated')->nullable()->useCurrent();
            $table->integer('document_count')->default(0);
            $table->string('frequency', 50)->nullable();
            $table->timestamps();

            $table->index('department_id');
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pms_kpi_tasks');
    }
};
