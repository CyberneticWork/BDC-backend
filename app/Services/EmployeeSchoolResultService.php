<?php

namespace App\Services;

use App\Models\EmployeeSchoolResult;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeSchoolResultService
{
    public function ensureSchema(): void
    {
        if (Schema::hasTable('employee_school_results')) {
            return;
        }

        Schema::create('employee_school_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->string('ol_english_grade', 5)->nullable();
            $table->string('ol_maths_grade', 5)->nullable();
            $table->unsignedSmallInteger('ol_year')->nullable();
            $table->string('al_syllabus', 20)->nullable();
            $table->string('al_stream', 100)->nullable();
            $table->unsignedSmallInteger('al_year')->nullable();
            $table->timestamps();
        });
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('employee_school_results');
    }

    /**
     * Save O/L and/or A/L details. Only the sections passed as enabled are written,
     * so switching one add-on off never clears data captured under the other.
     *
     * Call ensureSchema() before opening a DB transaction: CREATE TABLE commits implicitly in MySQL.
     */
    public function save(employee $employee, array $input, bool $olEnabled, bool $alEnabled): void
    {
        if (!$olEnabled && !$alEnabled) {
            return;
        }

        $data = [];

        if ($olEnabled) {
            $ol = is_array($input['ol'] ?? null) ? $input['ol'] : [];
            $data['ol_english_grade'] = $this->grade($ol['englishGrade'] ?? $ol['english_grade'] ?? null, 'O/L English');
            $data['ol_maths_grade'] = $this->grade($ol['mathsGrade'] ?? $ol['maths_grade'] ?? null, 'O/L Mathematics');
            $data['ol_year'] = $this->year($ol['yearSat'] ?? $ol['year'] ?? null, 'O/L year sat');
        }

        if ($alEnabled) {
            $al = is_array($input['al'] ?? null) ? $input['al'] : [];
            $syllabus = trim((string) ($al['syllabus'] ?? ''));
            if ($syllabus !== '' && !in_array($syllabus, EmployeeSchoolResult::AL_SYLLABUSES, true)) {
                throw new HttpException(422, 'A/L syllabus must be National, Cambridge or AQA.');
            }
            $stream = trim((string) ($al['stream'] ?? ''));
            if (mb_strlen($stream) > 100) {
                throw new HttpException(422, 'A/L subject stream must be 100 characters or fewer.');
            }
            $data['al_syllabus'] = $syllabus !== '' ? $syllabus : null;
            $data['al_stream'] = $stream !== '' ? $stream : null;
            $data['al_year'] = $this->year($al['yearSat'] ?? $al['year'] ?? null, 'A/L year sat');
        }

        EmployeeSchoolResult::updateOrCreate(['employee_id' => $employee->id], $data);
    }

    private function grade($value, string $label): ?string
    {
        $grade = strtoupper(trim((string) ($value ?? '')));
        if ($grade === '') {
            return null;
        }
        if (!in_array($grade, EmployeeSchoolResult::OL_GRADES, true)) {
            throw new HttpException(422, "{$label}: choose a grade from the list.");
        }
        return $grade;
    }

    private function year($value, string $label): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < 1950 || (int) $raw > (int) date('Y') + 1) {
            throw new HttpException(422, "{$label} must be a 4-digit year.");
        }
        return (int) $raw;
    }
}
