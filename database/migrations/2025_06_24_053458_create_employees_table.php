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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('attendance_employee_no')->unique();
            $table->string('epf')->unique();
            $table->string('nic')->unique();
            $table->date('dob');
            $table->enum('gender', ['male', 'female', 'other']);

            $table->string('name_with_initials');
            $table->string('full_name');
            $table->string('display_name')->nullable();

             $table->boolean('is_active')->default(true);

            $table->foreignId('employment_type_id')->constrained('employment_types')->onDelete('cascade');

            $table->foreignId('organization_assignment_id')->constrained('organization_assignments')->onDelete('cascade');
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable();

            $table->foreignId('spouse_id')->nullable()->constrained('spouses')->onDelete('cascade');

            $table->string('profile_photo_path', 2048)->nullable();

            $table->string('religion')->nullable();
            $table->string('country_of_birth')->nullable();

            $table->softDeletes();

            $table->timestamps();

            $table->index('nic');
            $table->index('epf');
            $table->index('attendance_employee_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
