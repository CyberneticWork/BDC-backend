<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDiscountLevelsTable extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('discount_levels', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->date('date');
			$table->integer('days')->default(0);
			$table->text('description')->nullable();
			$table->decimal('value', 10, 2)->default(0);
			$table->unsignedBigInteger('created_by')->nullable();
			$table->timestamps();

			$table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::dropIfExists('discount_levels');
	}
}

