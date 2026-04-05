<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // අලුත් Status ඔක්කොම එකතු කරලා DB එක Update කරනවා
        DB::statement("ALTER TABLE leave_masters MODIFY COLUMN status ENUM('Pending', 'Pending_Supervisor', 'Pending_Manager', 'Pending_MD', 'Approved', 'HR_Approved', 'Rejected') NOT NULL DEFAULT 'Pending'");
    }

    public function down()
    {
        //  (Rollback
        DB::statement("ALTER TABLE leave_masters MODIFY COLUMN status ENUM('Pending', 'Approved', 'HR_Approved', 'Rejected') NOT NULL DEFAULT 'Pending'");
    }
};