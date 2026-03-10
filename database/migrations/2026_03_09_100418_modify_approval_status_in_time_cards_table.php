<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE time_cards MODIFY approval_status ENUM('Pending', 'Active', 'Rejected') DEFAULT 'Pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE time_cards MODIFY approval_status ENUM('Pending', 'Approved', 'Cancelled') DEFAULT 'Pending'");
    }
};