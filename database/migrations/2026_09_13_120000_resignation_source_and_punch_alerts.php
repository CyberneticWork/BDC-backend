<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('resignations') && !Schema::hasColumn('resignations', 'submitted_via')) {
            Schema::table('resignations', function (Blueprint $table) {
                $table->string('submitted_via', 20)->default('hr')->after('status');
            });
        }

        if (!Schema::hasTable('attendance_punch_alerts')) {
            Schema::create('attendance_punch_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->date('work_date');
                $table->string('kind', 20);
                $table->unsignedBigInteger('shift_id')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'work_date', 'kind', 'shift_id'], 'punch_alert_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punch_alerts');
        if (Schema::hasTable('resignations') && Schema::hasColumn('resignations', 'submitted_via')) {
            Schema::table('resignations', function (Blueprint $table) {
                $table->dropColumn('submitted_via');
            });
        }
    }
};
