<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('salary_processes', function (Blueprint $table) {
            // අලුත් Column එක දානවා
            $table->json('coinage_breakdown')->nullable()->after('salary_breakdown');
        });
    }

    public function down()
    {
        Schema::table('salary_processes', function (Blueprint $table) {
            $table->dropColumn('coinage_breakdown');
        });
    }
};