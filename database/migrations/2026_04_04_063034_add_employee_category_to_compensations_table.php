<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
    {
        //  compensations  compensation 
        Schema::table('compensation', function (Blueprint $table) {
            $table->string('employee_category')->nullable()->after('employee_id');
        });
    }

    public function down()
    {
        //  compensation 
        Schema::table('compensation', function (Blueprint $table) {
            $table->dropColumn('employee_category');
        });
    }
};
