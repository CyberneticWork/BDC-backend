<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loans') || Schema::hasColumn('loans', 'request_date')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->date('request_date')->nullable()->after('installment_amount');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('loans') && Schema::hasColumn('loans', 'request_date')) {
            Schema::table('loans', function (Blueprint $table) {
                $table->dropColumn('request_date');
            });
        }
    }
};
