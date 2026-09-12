<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('time_cards')) {
            Schema::table('time_cards', function (Blueprint $table) {
                if (!Schema::hasColumn('time_cards', 'entry_source')) {
                    $table->string('entry_source', 20)->nullable()->after('approval_status');
                }
                if (!Schema::hasColumn('time_cards', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('entry_source');
                }
            });
        }

        if (!Schema::hasTable('time_card_audits')) {
            Schema::create('time_card_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('time_card_id')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->date('entry_date')->nullable();
                $table->string('action', 30);
                $table->string('source', 20)->nullable();
                $table->text('reason')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();

                $table->index(['entry_date', 'action']);
                $table->index('time_card_id');
                $table->index('employee_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('time_card_audits');

        if (Schema::hasTable('time_cards')) {
            Schema::table('time_cards', function (Blueprint $table) {
                if (Schema::hasColumn('time_cards', 'created_by')) {
                    $table->dropColumn('created_by');
                }
                if (Schema::hasColumn('time_cards', 'entry_source')) {
                    $table->dropColumn('entry_source');
                }
            });
        }
    }
};
