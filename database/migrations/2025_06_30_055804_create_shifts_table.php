<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            $table->string('shift_code')->unique();
            $table->string('shift_description');

            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->time('morning_ot_start')->nullable();
          
            $table->boolean('midnight_roster')->default(false);
         
            $table->softDeletes();
            $table->timestamps();


            $table->index('shift_code');
            $table->index('start_time');
            $table->index('end_time');
            $table->index('morning_ot_start');
          

        });
    }

    public function down()
    {
        Schema::dropIfExists('shifts');
    }
};
