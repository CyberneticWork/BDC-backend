<?php

namespace App\Services;

use App\Models\EmployeePreviousEmployment;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeePreviousEmploymentService
{
    public function ensureSchema(): void
    {
        if (Schema::hasTable('employee_previous_employments')) {
            return;
        }

        Schema::create('employee_previous_employments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('organization_name', 191);
            $table->string('last_designation', 191)->nullable();
            $table->date('join_date')->nullable();
            $table->date('last_date')->nullable();
            $table->text('comments')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('employee_previous_employments');
    }

    /**
     * Replace the employee's previous employment history. Rows with no organization,
     * designation or comments are treated as empty form lines and skipped.
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

            $organization = trim((string) ($row['organizationName'] ?? $row['organization_name'] ?? ''));
            $designation = trim((string) ($row['lastDesignation'] ?? $row['last_designation'] ?? ''));
            $comments = trim((string) ($row['comments'] ?? ''));
            $joinRaw = $row['joinDate'] ?? $row['join_date'] ?? null;
            $lastRaw = $row['lastDate'] ?? $row['last_date'] ?? null;
            if ($organization === '' && $designation === '' && $comments === '' && !$joinRaw && !$lastRaw) {
                continue;
            }

            $line = $index + 1;
            if ($organization === '') {
                throw new HttpException(422, "Previous employment line {$line}: organization name is required.");
            }

            $join = $this->date($joinRaw, "Previous employment line {$line}: join date");
            $last = $this->date($lastRaw, "Previous employment line {$line}: last date");
            if ($join && $last && $last < $join) {
                throw new HttpException(422, "Previous employment line {$line}: last date cannot be before the join date.");
            }

            if ($comments !== '') {
                $words = preg_split('/\s+/u', $comments, -1, PREG_SPLIT_NO_EMPTY);
                if (count($words) > EmployeePreviousEmployment::COMMENT_WORD_LIMIT) {
                    throw new HttpException(422, "Previous employment line {$line}: comments must be "
                        . EmployeePreviousEmployment::COMMENT_WORD_LIMIT . ' words or fewer.');
                }
            }

            $clean[] = [
                'employee_id' => $employee->id,
                'organization_name' => mb_substr($organization, 0, 191),
                'last_designation' => $designation !== '' ? mb_substr($designation, 0, 191) : null,
                'join_date' => $join,
                'last_date' => $last,
                'comments' => $comments !== '' ? $comments : null,
                'sort_order' => count($clean),
            ];
        }

        EmployeePreviousEmployment::where('employee_id', $employee->id)->delete();
        foreach ($clean as $data) {
            EmployeePreviousEmployment::create($data);
        }
    }

    /** Returns "Y-m-d" or null for blank input. */
    private function date($value, string $label): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $raw = substr($raw, 0, 10);
        $parsed = \DateTime::createFromFormat('!Y-m-d', $raw);
        if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
            throw new HttpException(422, "{$label} must be a valid date.");
        }
        return $raw;
    }
}
