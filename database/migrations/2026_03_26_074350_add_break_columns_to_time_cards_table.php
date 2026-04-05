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
        Schema::table('time_cards', function (Blueprint $table) {
            $table->string('reason')->nullable(); 
            $table->enum('break_status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_cards', function (Blueprint $table) {
            $table->dropColumn(['reason', 'break_status']);
        });
    }
};