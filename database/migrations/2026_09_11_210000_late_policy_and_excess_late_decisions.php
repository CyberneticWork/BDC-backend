<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'late_attendance_policy_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('late_attendance_policy_enabled')->default(false);
            });
        }

        if (!Schema::hasTable('excess_late_decisions')) {
            Schema::create('excess_late_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->date('late_date');
                $table->unsignedInteger('late_minutes')->default(0);
                $table->unsignedInteger('shift_minutes')->default(480);
                $table->string('action', 20)->default('pending'); // pending, leave, reject
                $table->string('leave_type', 50)->nullable();
                $table->unsignedBigInteger('leave_id')->nullable();
                $table->unsignedBigInteger('nopay_id')->nullable();
                $table->string('deduct_from', 20)->nullable(); // basic, bonus
                $table->decimal('nopay_days', 10, 4)->default(0);
                $table->decimal('nopay_amount', 12, 2)->default(0);
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['employee_id', 'late_date'], 'excess_late_emp_date_unique');
                $table->index(['late_date', 'action']);
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_late_decisions');
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'late_attendance_policy_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('late_attendance_policy_enabled');
            });
        }
    }
};
