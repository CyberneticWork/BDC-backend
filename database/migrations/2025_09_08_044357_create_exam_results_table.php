<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('exam_id')->constrained('exams');
            $table->integer('attempt_number')->default(1); // added
            $table->integer('score');
            $table->boolean('passed');
            $table->json('answers')->nullable(); // added
            $table->timestamp('submitted_at')->useCurrent();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id','exam_id']);
            $table->index(['user_id','exam_id','attempt_number']); // added
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
