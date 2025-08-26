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
        Schema::create('leave_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');

            $table->date('reporting_date');
            $table->string('leave_type');
            $table->date('leave_date')->nullable();
            $table->date('leave_from')->nullable();
            $table->date('leave_to')->nullable();
            $table->string('period')->nullable();
            $table->text('reason')->nullable();
            $table->integer('count')->nullable()->default(1);
            $table->enum('status', ['Pending', 'Approved', 'HR_Approved', 'Rejected'])->default('Pending');
            $table->decimal('over_limit', 10, 2)->nullable();
            $table->boolean('is_half_day')->default(false);
            $table->decimal('leave_duration', 5, 2)->default(1.00);

            $table->softDeletes();
            $table->timestamps();

            $table->index('employee_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_masters');
    }
};
