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
        Schema::create('kpi_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_task_id')->constrained('kpi_tasks');
            $table->foreignId('creator_role_id')->constrained('creator_roles');
            $table->unsignedBigInteger('creator_id')->nullable(); // Remove ->after() when creating table
            $table->json('weights'); // Store performance criteria weights as JSON
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade'); // Link to company table
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null'); // Link to department table
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade'); // Link to employee table
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('active'); // active, attention, inactive
            $table->string('priority')->default('medium'); // high, medium, low
            $table->text('description')->nullable();
            $table->string('completion_status')->default('not-started'); // not-started, pending, in-progress, completed
            $table->string('approval_status')->nullable()->default('pending'); // Move this before timestamps
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Add foreign key constraint for creator_id
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('kpi_task_assignments', function (Blueprint $table) {
            $table->boolean('kpi_type')->default(0)->after('completion_status'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpi_task_assignments', function (Blueprint $table) {
            $table->dropColumn('kpi_type');
        });

        Schema::dropIfExists('kpi_task_assignments');
    }
};
