<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('no_pay_records', function (Blueprint $table) {
            if (!Schema::hasColumn('no_pay_records', 'type')) {
                $table->string('type')->nullable()->after('description');
            }

            if (!Schema::hasColumn('no_pay_records', 'hours')) {
                $table->decimal('hours', 5, 2)->nullable()->after('type');
            }

            if (!Schema::hasColumn('no_pay_records', 'minutes')) {
                $table->integer('minutes')->nullable()->after('hours');
            }

            if (!Schema::hasColumn('no_pay_records', 'start_time')) {
                $table->time('start_time')->nullable()->after('minutes');
            }

            if (!Schema::hasColumn('no_pay_records', 'end_time')) {
                $table->time('end_time')->nullable()->after('start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('no_pay_records', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('no_pay_records', 'type')) {
                $columns[] = 'type';
            }

            if (Schema::hasColumn('no_pay_records', 'hours')) {
                $columns[] = 'hours';
            }

            if (Schema::hasColumn('no_pay_records', 'minutes')) {
                $columns[] = 'minutes';
            }

            if (Schema::hasColumn('no_pay_records', 'start_time')) {
                $columns[] = 'start_time';
            }

            if (Schema::hasColumn('no_pay_records', 'end_time')) {
                $columns[] = 'end_time';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};