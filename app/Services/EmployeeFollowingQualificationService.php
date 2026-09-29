<?php

namespace App\Services;

use App\Models\EmployeeFollowingQualification;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeFollowingQualificationService
{
    private const OPTIONAL_COLUMNS = ['qualification_name', 'institute_name', 'start_year', 'start_month', 'lecture_type'];

    private static bool $relaxed = false;

    public function ensureSchema(): void
    {
        if (Schema::hasTable('employee_following_qualifications')) {
            $this->relaxRequiredColumns();
            return;
        }

        Schema::create('employee_following_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('qualification_name', 191)->nullable();
            $table->string('institute_name', 191)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->string('lecture_type', 10)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('employee_following_qualifications');
    }

    /** Tables created before education fields became optional had NOT NULL columns. */
    private function relaxRequiredColumns(): void
    {
        if (self::$relaxed) {
            return;
        }
        self::$relaxed = true;
        try {
            $strict = collect(Schema::getColumns('employee_following_qualifications'))
                ->filter(fn ($c) => in_array($c['name'], self::OPTIONAL_COLUMNS, true) && !$c['nullable'])
                ->pluck('name');
            if ($strict->isEmpty()) {
                return;
            }
            Schema::table('employee_following_qualifications', function (Blueprint $table) use ($strict) {
                if ($strict->contains('qualification_name')) {
                    $table->string('qualification_name', 191)->nullable()->change();
                }
                if ($strict->contains('institute_name')) {
                    $table->string('institute_name', 191)->nullable()->change();
                }
                if ($strict->contains('start_year')) {
                    $table->unsignedSmallInteger('start_year')->nullable()->change();
                }
                if ($strict->contains('start_month')) {
                    $table->unsignedTinyInteger('start_month')->nullable()->change();
                }
                if ($strict->contains('lecture_type')) {
                    $table->string('lecture_type', 10)->nullable()->change();
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Could not make employee_following_qualifications columns nullable: '.$e->getMessage());
        }
    }

    /**
     * Replace the employee's following qualifications. Every field is optional; rows with
     * nothing filled in are skipped.
     *
     * Call ensureSchema() before opening a DB transaction: CREATE TABLE commits implicitly in MySQL.
     */
    public function sync(employee $employee, array $rows): void
    {
        $clean = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['qualificationName'] ?? $row['qualification_name'] ?? ''));
            $institute = trim((string) ($row['instituteName'] ?? $row['institute_name'] ?? ''));
            $startRaw = trim((string) ($row['startMonth'] ?? ''));
            $endRaw = trim((string) ($row['endMonth'] ?? ''));
            $lecture = trim((string) ($row['lectureType'] ?? $row['lecture_type'] ?? ''));
            if ($name === '' && $institute === '' && $startRaw === '' && $endRaw === '' && $lecture === '') {
                continue;
            }

            $line = $index + 1;
            $start = $this->month($startRaw, "Following qualification line {$line}: starting year and month");
            $end = $this->month($endRaw, "Following qualification line {$line}: ending year and month");
            if ($start !== null && $end !== null && ($end[0] * 12 + $end[1]) < ($start[0] * 12 + $start[1])) {
                throw new HttpException(422, "Following qualification line {$line}: ending month cannot be before the starting month.");
            }

            if ($lecture !== '' && !in_array($lecture, EmployeeFollowingQualification::LECTURE_TYPES, true)) {
                throw new HttpException(422, "Following qualification line {$line}: choose Weekday or Weekend lectures.");
            }

            $clean[] = [
                'employee_id' => $employee->id,
                'qualification_name' => $name !== '' ? mb_substr($name, 0, 191) : null,
                'institute_name' => $institute !== '' ? mb_substr($institute, 0, 191) : null,
                'start_year' => $start[0] ?? null,
                'start_month' => $start[1] ?? null,
                'end_year' => $end[0] ?? null,
                'end_month' => $end[1] ?? null,
                'lecture_type' => $lecture !== '' ? $lecture : null,
                'sort_order' => count($clean),
            ];
        }

        EmployeeFollowingQualification::where('employee_id', $employee->id)->delete();
        foreach ($clean as $data) {
            EmployeeFollowingQualification::create($data);
        }
    }

    /** Parses "YYYY-MM" into [year, month]; blank returns null. */
    private function month($value, string $label): ?array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw, $m)
            || (int) $m[1] < 1950 || (int) $m[1] > (int) date('Y') + 10) {
            throw new HttpException(422, "{$label} must be a valid month.");
        }
        return [(int) $m[1], (int) $m[2]];
    }
}
