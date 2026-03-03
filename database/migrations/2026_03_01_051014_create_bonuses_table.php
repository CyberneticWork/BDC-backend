<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bonuses', function (Blueprint $table) {
            $table->id();

            $table->string('bonus_code')->unique();
            $table->string('bonus_name');

            $table->enum('bonus_type', ['fixed', 'variable'])->default('fixed');
            $table->decimal('amount', 12, 2)->default(0);

            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->date('fixed_date')->nullable();
            $table->date('variable_from')->nullable();
            $table->date('variable_to')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys (ඔයාගේ companies/departments tables තියෙනවනම්)
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonuses');
    }
};