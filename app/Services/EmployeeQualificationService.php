<?php

namespace App\Services;

use App\Models\EmployeeQualification;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeQualificationService
{
    private static bool $relaxed = false;

    public function ensureSchema(): void
    {
        if (Schema::hasTable('employee_qualifications')) {
            $this->relaxRequiredColumns();
            return;
        }

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('status', 20)->default(EmployeeQualification::STATUS_COMPLETED);
            $table->string('qualification_type', 80)->nullable();
            $table->string('course_name', 191)->nullable();
            $table->string('institute_name', 191)->nullable();
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('employee_qualifications');
    }

    /** Tables created before education fields became optional had NOT NULL type/institute. */
    private function relaxRequiredColumns(): void
    {
        if (self::$relaxed) {
            return;
        }
        self::$relaxed = true;
        try {
            $strict = collect(Schema::getColumns('employee_qualifications'))
                ->filter(fn ($c) => in_array($c['name'], ['qualification_type', 'institute_name'], true) && !$c['nullable'])
                ->pluck('name');
            if ($strict->isEmpty()) {
                return;
            }
            Schema::table('employee_qualifications', function (Blueprint $table) use ($strict) {
                if ($strict->contains('qualification_type')) {
                    $table->string('qualification_type', 80)->nullable()->change();
                }
                if ($strict->contains('institute_name')) {
                    $table->string('institute_name', 191)->nullable()->change();
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Could not make employee_qualifications columns nullable: '.$e->getMessage());
        }
    }

    /**
     * Replace the employee's completed qualifications. Every field is optional; rows with
     * nothing filled in are skipped. Qualifications still being followed live in
     * employee_following_qualifications.
     *
     * Call ensureSchema() before opening a DB transaction: CREATE TABLE commits implicitly in MySQL.
     */
    public function sync(employee $employee, array $rows): void
    {
        $clean = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row) || strtolower(trim((string) ($row['status'] ?? ''))) === EmployeeQualification::STATUS_FOLLOWING) {
                continue;
            }

            $type = trim((string) ($row['qualificationType'] ?? $row['qualification_type'] ?? ''));
            $institute = trim((string) ($row['instituteName'] ?? $row['institute_name'] ?? ''));
            $course = trim((string) ($row['courseName'] ?? $row['course_name'] ?? ''));
            $yearRaw = trim((string) ($row['completionYear'] ?? $row['completion_year'] ?? ''));
            if ($type === '' && $institute === '' && $course === '' && $yearRaw === '') {
                continue;
            }

            $line = $index + 1;
            if ($type !== '' && !in_array($type, EmployeeQualification::TYPES, true)) {
                throw new HttpException(422, "Qualification line {$line}: choose a qualification type from the list.");
            }

            $year = null;
            if ($yearRaw !== '') {
                if (!ctype_digit($yearRaw) || (int) $yearRaw < 1950 || (int) $yearRaw > (int) date('Y') + 10) {
                    throw new HttpException(422, "Qualification line {$line}: completion year must be a 4-digit year.");
                }
                $year = (int) $yearRaw;
            }

            $clean[] = [
                'employee_id' => $employee->id,
                'status' => EmployeeQualification::STATUS_COMPLETED,
                'qualification_type' => $type !== '' ? $type : null,
                'course_name' => $course !== '' ? mb_substr($course, 0, 191) : null,
                'institute_name' => $institute !== '' ? mb_substr($institute, 0, 191) : null,
                'completion_year' => $year,
                'sort_order' => count($clean),
            ];
        }

        EmployeeQualification::where('employee_id', $employee->id)
            ->where('status', EmployeeQualification::STATUS_COMPLETED)
            ->delete();
        foreach ($clean as $data) {
            EmployeeQualification::create($data);
        }
    }
}
