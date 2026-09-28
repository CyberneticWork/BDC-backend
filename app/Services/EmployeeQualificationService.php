<?php

namespace App\Services;

use App\Models\EmployeeQualification;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeQualificationService
{
    public function ensureSchema(): void
    {
        if (Schema::hasTable('employee_qualifications')) {
            return;
        }

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('status', 20)->default(EmployeeQualification::STATUS_COMPLETED);
            $table->string('qualification_type', 80);
            $table->string('course_name', 191)->nullable();
            $table->string('institute_name', 191);
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('employee_qualifications');
    }

    /**
     * Replace the employee's qualifications. Rows with no type and no institute
     * are treated as empty form lines and skipped.
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

            $type = trim((string) ($row['qualificationType'] ?? $row['qualification_type'] ?? ''));
            $institute = trim((string) ($row['instituteName'] ?? $row['institute_name'] ?? ''));
            if ($type === '' && $institute === '') {
                continue;
            }

            $line = $index + 1;
            if (!in_array($type, EmployeeQualification::TYPES, true)) {
                throw new HttpException(422, "Qualification line {$line}: choose a qualification type from the list.");
            }
            if ($institute === '') {
                throw new HttpException(422, "Qualification line {$line}: name of institute is required.");
            }

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status !== EmployeeQualification::STATUS_FOLLOWING) {
                $status = EmployeeQualification::STATUS_COMPLETED;
            }

            $yearRaw = trim((string) ($row['completionYear'] ?? $row['completion_year'] ?? ''));
            $year = null;
            if ($yearRaw !== '') {
                if (!ctype_digit($yearRaw) || (int) $yearRaw < 1950 || (int) $yearRaw > (int) date('Y') + 10) {
                    throw new HttpException(422, "Qualification line {$line}: completion year must be a 4-digit year.");
                }
                $year = (int) $yearRaw;
            }
            if ($status === EmployeeQualification::STATUS_COMPLETED && $year === null) {
                throw new HttpException(422, "Qualification line {$line}: completion year is required.");
            }

            $course = trim((string) ($row['courseName'] ?? $row['course_name'] ?? ''));

            $clean[] = [
                'employee_id' => $employee->id,
                'status' => $status,
                'qualification_type' => $type,
                'course_name' => $course !== '' ? $course : null,
                'institute_name' => $institute,
                'completion_year' => $year,
                'sort_order' => count($clean),
            ];
        }

        EmployeeQualification::where('employee_id', $employee->id)->delete();
        foreach ($clean as $data) {
            EmployeeQualification::create($data);
        }
    }
}
