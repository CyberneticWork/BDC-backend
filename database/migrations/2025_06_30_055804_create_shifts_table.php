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
            $table->time('morning_ot_end')->nullable();
            $table->decimal('morning_ot_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('morning_ot_max_minutes')->nullable();
            
            $table->time('night_ot_start')->nullable();
            $table->time('night_ot_end')->nullable();
            $table->unsignedSmallInteger('night_normal_ot_max_minutes')->nullable();
            $table->decimal('night_normal_ot_rate', 5, 2)->nullable();
            $table->decimal('night_special_ot_rate', 5, 2)->nullable();
          
            $table->boolean('midnight_roster')->default(false);
         
            $table->softDeletes();
            $table->timestamps();


            $table->index('shift_code');
            $table->index('start_time');
            $table->index('end_time');
            $table->index('morning_ot_start');
            $table->index('morning_ot_end');
            $table->index('night_ot_start');
            $table->index('night_ot_end');
          

        });
    }

    public function down()
    {
        Schema::dropIfExists('shifts');
    }
};
