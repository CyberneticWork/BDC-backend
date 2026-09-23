<?php

use App\Models\HrRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            try {
                DB::statement("ALTER TABLE `users` MODIFY `role` VARCHAR(40) NOT NULL DEFAULT 'user'");
            } catch (\Throwable) {
            }
        }

        if (!Schema::hasTable('hr_roles')) {
            Schema::create('hr_roles', function (Blueprint $table) {
                $table->id();
                $table->string('role_key', 40)->unique();
                $table->string('name', 80);
                $table->string('based_on', 40)->default('user');
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
        }

        $now = now();
        foreach (HrRole::SYSTEM as $row) {
            if (!HrRole::query()->where('role_key', $row['role_key'])->exists()) {
                HrRole::query()->create([
                    'role_key' => $row['role_key'],
                    'name' => $row['name'],
                    'based_on' => $row['based_on'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_roles');
    }
};