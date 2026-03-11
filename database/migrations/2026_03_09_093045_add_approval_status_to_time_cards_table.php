<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('time_cards', function (Blueprint $table) {
            $table->enum('approval_status', ['Pending', 'Approved', 'Cancelled'])
                ->default('Pending')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('time_cards', function (Blueprint $table) {
            $table->dropColumn('approval_status');
        });
    }
};