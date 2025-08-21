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
        Schema::create('salary_process_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salary_process_id');
            $table->unsignedBigInteger('user_id')->nullable(); // ID of user who made the change
            $table->string('user_name')->nullable(); // Name of user who made the change
            $table->string('action')->default('update'); // Type of action (update/delete/etc)
            $table->text('changes')->nullable(); // JSON of changed fields
            $table->timestamp('created_at')->useCurrent();
            
            // Index for faster lookups
            $table->index('salary_process_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_process_audits');
    }
};
