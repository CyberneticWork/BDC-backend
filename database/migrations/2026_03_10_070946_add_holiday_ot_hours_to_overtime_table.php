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
	if(!Schema::hasColumn('over_times', 'holiday_ot_hours')) {
        	Schema::table('over_times', function (Blueprint $table) {
         	   	$table->integer('holiday_ot_hours')->default(0);
        	});
	}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
	if(Schema::hasColumn('over_times', 'holiday_ot_hours')) {
        	Schema::table('over_times', function (Blueprint $table) {
            		$table->dropColumn('holiday_ot_hours');
        	});
	}
    }
};
