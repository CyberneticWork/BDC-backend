<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('weekly_off_entries')) {
            Schema::create('weekly_off_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('employee_id');
                $table->date('off_date');
                $table->decimal('days', 8, 4)->default(1);
                $table->string('source', 20)->default('portal');
                $table->string('status', 20)->default('Pending');
                $table->string('reason', 1000)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['employee_id', 'off_date']);
                $table->index(['company_id', 'status']);
            });
        }

        if (!Schema::hasTable('employee_medical_quotas')) {
            Schema::create('employee_medical_quotas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedSmallInteger('year');
                $table->decimal('allocated_amount', 12, 2)->default(0);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'year']);
            });
        }

        if (!Schema::hasTable('medical_claims')) {
            Schema::create('medical_claims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedSmallInteger('year');
                $table->decimal('amount', 12, 2);
                $table->string('description', 1000)->nullable();
                $table->string('bill_path', 500)->nullable();
                $table->string('bill_name', 255)->nullable();
                $table->string('status', 20)->default('PENDING');
                $table->string('review_note', 500)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['employee_id', 'status']);
            });
        }

        if (!Schema::hasTable('pending_payments')) {
            Schema::create('pending_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('employee_id');
                $table->string('source_type', 40);
                $table->unsignedBigInteger('source_id');
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('PENDING');
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
                $table->index(['status', 'source_type']);
                $table->unique(['source_type', 'source_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payments');
        Schema::dropIfExists('medical_claims');
        Schema::dropIfExists('employee_medical_quotas');
        Schema::dropIfExists('weekly_off_entries');
    }
};
