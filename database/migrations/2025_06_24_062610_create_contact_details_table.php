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
        Schema::create('contact_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('permanent_address');
            $table->string('temporary_address')->nullable();
            $table->string('email');
            $table->string('land_line')->nullable();
            $table->string('mobile_line')->unique();
            $table->string('gn_division')->nullable();
            $table->string('police_station')->nullable();
            $table->string('district')->nullable();
            $table->string('province')->nullable();
            $table->string('electoral_division')->nullable();
            $table->string('emg_relationship')->nullable();
            $table->string('emg_name')->nullable();
            $table->string('emg_address')->nullable();
            $table->string('emg_tel')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('email');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_details');
    }
};
