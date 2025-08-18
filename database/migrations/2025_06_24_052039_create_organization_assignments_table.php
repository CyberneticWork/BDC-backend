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
        Schema::create('organization_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('set null');
            $table->string('current_supervisor')->nullable();
            $table->date('date_of_joining')->nullable();

            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('sub_department_id')->nullable()->constrained('sub_departments')->onDelete('set null');

            // to do list. if designation not good add enum
            $table->foreignId('designation_id')->nullable()->constrained('designations')->onDelete('set null');
            $table->enum('day_off', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'none'])->nullable()->default('none');
            $table->date('confirmation_date')->nullable();

            $table->boolean('probationary_period')->default(false);
            $table->boolean('training_period')->default(false);
            $table->boolean('contract_period')->default(false);

            $table->date('probationary_period_from')->nullable();
            $table->date('probationary_period_to')->nullable();
            $table->date('training_period_from')->nullable();
            $table->date('training_period_to')->nullable();
            $table->date('contract_period_from')->nullable();
            $table->date('contract_period_to')->nullable();

            $table->date('date_of_resigning')->nullable();
            $table->text('resigned_reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('letter_path')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('company_id');
            $table->index('department_id');
            $table->index('sub_department_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_assignments');
    }
};
