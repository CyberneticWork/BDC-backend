<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_setting_quarters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_setting_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter_number');
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('start_month');
            $table->unsignedTinyInteger('end_month');
            $table->unsignedInteger('leave_days')->default(0);
            $table->timestamps();

            $table->unique(['leave_setting_id', 'quarter_number']);
        });

        Schema::create('leave_setting_quarter_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quarter_id')->constrained('leave_setting_quarters')->cascadeOnDelete();
            $table->string('type_key')->nullable();
            $table->string('name');
            $table->unsignedInteger('days')->default(0);
            $table->timestamps();
        });

        if (Schema::hasColumn('leave_settings', 'quarters')) {
            $settings = DB::table('leave_settings')->select('id', 'quarters')->whereNotNull('quarters')->get();

            foreach ($settings as $setting) {
                $quarters = json_decode($setting->quarters, true) ?? [];

                foreach ($quarters as $quarter) {
                    $startMonth = $quarter['start_month'] ?? ($quarter['months']['start'] ?? $quarter['months'][0] ?? 1);
                    $endMonth = $quarter['end_month'] ?? ($quarter['months']['end'] ?? $quarter['months'][1] ?? $startMonth);

                    $quarterId = DB::table('leave_setting_quarters')->insertGetId([
                        'leave_setting_id' => $setting->id,
                        'quarter_number' => $quarter['quarter_number'] ?? 0,
                        'name' => $quarter['name'] ?? null,
                        'start_month' => $startMonth,
                        'end_month' => $endMonth,
                        'leave_days' => $quarter['leave_days'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (!empty($quarter['leave_types']) && is_array($quarter['leave_types'])) {
                        foreach ($quarter['leave_types'] as $leaveType) {
                            DB::table('leave_setting_quarter_leave_types')->insert([
                                'quarter_id' => $quarterId,
                                'type_key' => $leaveType['type'] ?? null,
                                'name' => $leaveType['name'] ?? ($leaveType['type'] ?? 'Custom'),
                                'days' => $leaveType['days'] ?? 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            Schema::table('leave_settings', function (Blueprint $table) {
                $table->dropColumn('quarters');
            });
        }
    }

    public function down(): void
    {
        Schema::table('leave_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_settings', 'quarters')) {
                $table->json('quarters')->nullable();
            }
        });

        $quarters = DB::table('leave_setting_quarters')->get();
        foreach ($quarters as $quarter) {
            $leaveTypes = DB::table('leave_setting_quarter_leave_types')
                ->where('quarter_id', $quarter->id)
                ->get()
                ->map(function ($type) {
                    return [
                        'type' => $type->type_key,
                        'name' => $type->name,
                        'days' => $type->days,
                    ];
                })->toArray();

            $current = DB::table('leave_settings')->where('id', $quarter->leave_setting_id)->value('quarters');
            $existingQuarters = $current ? json_decode($current, true) : [];
            $existingQuarters[] = [
                'quarter_number' => $quarter->quarter_number,
                'name' => $quarter->name,
                'start_month' => $quarter->start_month,
                'end_month' => $quarter->end_month,
                'leave_days' => $quarter->leave_days,
                'leave_types' => $leaveTypes,
            ];

            DB::table('leave_settings')->where('id', $quarter->leave_setting_id)->update([
                'quarters' => json_encode($existingQuarters),
            ]);
        }

        Schema::dropIfExists('leave_setting_quarter_leave_types');
        Schema::dropIfExists('leave_setting_quarters');
    }
};
