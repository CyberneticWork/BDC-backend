<?php
namespace App\Imports;

use App\Models\bonuses;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BonusesImport implements ToCollection, WithHeadingRow
{
    private $errors = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $normalizedRow = $this->normalizeRow($row);

            if (!isset($normalizedRow['bonus_type'])) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => ['Missing required column: bonus_type']
                ];
                continue;
            }

            if (
                isset($normalizedRow['bonus_code']) &&
                bonuses::where('bonus_code', $normalizedRow['bonus_code'])->exists()
            ) {
                continue;
            }

            if (isset($normalizedRow['status'])) {
                $normalizedRow['status'] = strtolower(trim((string) $normalizedRow['status']));
            }
            if (isset($normalizedRow['bonus_type'])) {
                $normalizedRow['bonus_type'] = strtolower(trim((string) $normalizedRow['bonus_type']));
            }

            if (isset($normalizedRow['company_id'])) {
                $normalizedRow['company_id'] = $this->resolveCompanyId($normalizedRow['company_id']);
            }
            if (isset($normalizedRow['department_id'])) {
                $normalizedRow['department_id'] = $this->resolveDepartmentId(
                    $normalizedRow['department_id'],
                    $normalizedRow['company_id'] ?? null
                );
            }

            if (isset($normalizedRow['amount'])) {
                $normalizedRow['amount'] = $this->toNumeric($normalizedRow['amount']);
            }

            foreach (['fixed_date', 'variable_from', 'variable_to'] as $dateField) {
                if (array_key_exists($dateField, $normalizedRow)) {
                    $normalizedRow[$dateField] = $this->toNullableDate($normalizedRow[$dateField]);
                }
            }

            $validator = Validator::make($normalizedRow, [
                'bonus_code' => 'required|string',
                'bonus_name' => 'required|string|max:255',
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'bonus_type' => ['required', Rule::in(['fixed', 'variable'])],
                'company_id' => 'required|integer|exists:companies,id',
                'amount' => 'required|numeric|min:0',
                'department_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('departments', 'id')->where(function ($query) use ($normalizedRow) {
                        if (!empty($normalizedRow['company_id'])) {
                            $query->where('company_id', $normalizedRow['company_id']);
                        }
                    })
                ],
                'fixed_date' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return ($normalizedRow['bonus_type'] ?? null) === 'fixed';
                    })
                ],
                'variable_from' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return ($normalizedRow['bonus_type'] ?? null) === 'variable';
                    }),
                    function ($attribute, $value, $fail) use ($normalizedRow) {
                        if (
                            ($normalizedRow['bonus_type'] ?? null) === 'variable' &&
                            isset($normalizedRow['variable_to'], $value) &&
                            $value > $normalizedRow['variable_to']
                        ) {
                            $fail('The from date must be before the to date.');
                        }
                    }
                ],
                'variable_to' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return ($normalizedRow['bonus_type'] ?? null) === 'variable';
                    }),
                    'after_or_equal:variable_from'
                ]
            ]);

            if ($validator->fails()) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => $validator->errors()->all()
                ];
                continue;
            }

            $data = $validator->validated();
            $data = $this->prepareData($data);

            bonuses::create($data);
        }

        if (!empty($this->errors)) {
            $this->throwValidationException();
        }
    }

    private function normalizeRow($row)
    {
        $normalized = [];
        $mappings = [
            'bonus_code' => ['bonus_code', 'code', 'bonus code'],
            'bonus_name' => ['bonus_name', 'name', 'bonus name'],
            'status' => ['status'],
            'bonus_type' => ['bonus_type', 'type', 'bonus type'],
            'company_id' => ['company_id', 'company', 'company id'],
            'department_id' => ['department_id', 'department', 'department id'],
            'amount' => ['amount'],
            'fixed_date' => ['fixed_date', 'fixed date'],
            'variable_from' => ['variable_from', 'from date', 'start date'],
            'variable_to' => ['variable_to', 'to date', 'end date']
        ];

        foreach ($mappings as $field => $possibleHeaders) {
            foreach ($possibleHeaders as $header) {
                $header = strtolower(str_replace(' ', '_', $header));
                if (isset($row[$header])) {
                    $value = $row[$header];

                    if (is_string($value)) {
                        $value = trim($value);
                        if ($value === '') {
                            $value = null;
                        }
                    }

                    $normalized[$field] = $value;
                    break;
                }
            }
        }

        return $normalized;
    }

    private function prepareData($data)
    {
        if ($data['bonus_type'] === 'fixed') {
            $data['variable_from'] = null;
            $data['variable_to'] = null;
        } else {
            $data['fixed_date'] = null;
        }

        return $data;
    }

    private function toNullableDate($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $unixDate = ((int) $value - 25569) * 86400;
            return gmdate('Y-m-d', $unixDate);
        }

        try {
            $ts = strtotime((string) $value);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        } catch (\Throwable $e) {
        }

        return $value;
    }

    private function toNumeric($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return $value + 0;
        }
        return $value;
    }

    private function resolveCompanyId($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $name = trim((string) $value);
        $id = DB::table('companies')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        return $id ?: $value;
    }

    private function resolveDepartmentId($value, $companyId = null)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $name = trim((string) $value);
        $query = DB::table('departments')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        if (!empty($companyId)) {
            $query->where('company_id', $companyId);
        }
        $id = $query->value('id');

        return $id ?: $value;
    }

    private function throwValidationException()
    {
        $errorMessages = [];
        foreach ($this->errors as $error) {
            $errorMessages[] = "Row {$error['row']}: " . implode(', ', $error['errors']);
        }
        throw new \Exception(implode("\n", $errorMessages));
    }
}
