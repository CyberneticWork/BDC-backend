<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('loans', function (Blueprint $table) {
            // 'status' column එකට පස්සේ 'deduct_from' column එක හැදෙනවා (default අගය 'bonus' ලෙස)
            $table->string('deduct_from', 50)->default('bonus')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('loans', function (Blueprint $table) {
            // 
            $table->dropColumn('deduct_from');
        });
    }
};