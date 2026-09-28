<?php

namespace App\Services;

use App\Models\EmployeeFollowingQualification;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeFollowingQualificationService
{
    public function ensureSchema(): void
    {
        if (Schema::hasTable('employee_following_qualifications')) {
            return;
        }

        Schema::create('employee_following_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('qualification_name', 191);
            $table->string('institute_name', 191);
            $table->unsignedSmallInteger('start_year');
            $table->unsignedTinyInteger('start_month');
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->string('lecture_type', 10);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('employee_following_qualifications');
    }

    /**
     * Replace the employee's following qualifications. Rows with no name and no
     * institute are treated as empty form lines and skipped.
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
            if ($name === '' && $institute === '') {
                continue;
            }

            $line = $index + 1;
            if ($name === '') {
                throw new HttpException(422, "Following qualification line {$line}: name of the qualification is required.");
            }
            if ($institute === '') {
                throw new HttpException(422, "Following qualification line {$line}: institute is required.");
            }

            $start = $this->month($row['startMonth'] ?? null, "Following qualification line {$line}: starting year and month");
            if ($start === null) {
                throw new HttpException(422, "Following qualification line {$line}: starting year and month are required.");
            }
            $end = $this->month($row['endMonth'] ?? null, "Following qualification line {$line}: ending year and month");
            if ($end !== null && ($end[0] * 12 + $end[1]) < ($start[0] * 12 + $start[1])) {
                throw new HttpException(422, "Following qualification line {$line}: ending month cannot be before the starting month.");
            }

            $lecture = trim((string) ($row['lectureType'] ?? $row['lecture_type'] ?? ''));
            if (!in_array($lecture, EmployeeFollowingQualification::LECTURE_TYPES, true)) {
                throw new HttpException(422, "Following qualification line {$line}: choose Weekday or Weekend lectures.");
            }

            $clean[] = [
                'employee_id' => $employee->id,
                'qualification_name' => mb_substr($name, 0, 191),
                'institute_name' => mb_substr($institute, 0, 191),
                'start_year' => $start[0],
                'start_month' => $start[1],
                'end_year' => $end[0] ?? null,
                'end_month' => $end[1] ?? null,
                'lecture_type' => $lecture,
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
