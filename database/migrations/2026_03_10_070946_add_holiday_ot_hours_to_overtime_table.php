Schema::table('overtime', function (Blueprint $table) {
    $table->integer('holiday_ot_hours')->default(0);
});