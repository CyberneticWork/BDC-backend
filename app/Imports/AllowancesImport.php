<?php
namespace App\Imports;

use App\Models\allowances;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AllowancesImport implements ToCollection, WithHeadingRow
{
    private $errors = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            // Skip empty rows
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $normalizedRow = $this->normalizeRow($row);

            // Basic presence check for key column
            if (!isset($normalizedRow['allowance_type'])) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => ['Missing required column: allowance_type']
                ];
                continue;
            }

            // Skip existing allowance_code
            if (
                isset($normalizedRow['allowance_code']) &&
                Allowances::where('allowance_code', $normalizedRow['allowance_code'])->exists()
            ) {
                continue;
            }

            // Normalize enums and trim
            if (isset($normalizedRow['status'])) {
                $normalizedRow['status'] = strtolower(trim((string) $normalizedRow['status']));
            }
            if (isset($normalizedRow['allowance_type'])) {
                $normalizedRow['allowance_type'] = strtolower(trim((string) $normalizedRow['allowance_type']));
            }

            // Coerce IDs (accept IDs from dropdown; if names typed, try resolving to IDs)
            if (isset($normalizedRow['company_id'])) {
                $normalizedRow['company_id'] = $this->resolveCompanyId($normalizedRow['company_id']);
            }
            if (isset($normalizedRow['department_id'])) {
                $normalizedRow['department_id'] = $this->resolveDepartmentId(
                    $normalizedRow['department_id'],
                    $normalizedRow['company_id'] ?? null
                );
            }

            // Coerce amount
            if (isset($normalizedRow['amount'])) {
                $normalizedRow['amount'] = $this->toNumeric($normalizedRow['amount']);
            }

            // Convert Excel dates to YYYY-MM-DD
            foreach (['fixed_date', 'variable_from', 'variable_to'] as $dateField) {
                if (array_key_exists($dateField, $normalizedRow)) {
                    $normalizedRow[$dateField] = $this->toNullableDate($normalizedRow[$dateField]);
                }
            }

            $validator = Validator::make($normalizedRow, [
                'allowance_code' => 'required|string', // uniqueness handled above
                'allowance_name' => 'required|string|max:255',
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'allowance_type' => ['required', Rule::in(['fixed', 'variable'])],

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
                        return ($normalizedRow['allowance_type'] ?? null) === 'fixed';
                    })
                ],
                'variable_from' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return ($normalizedRow['allowance_type'] ?? null) === 'variable';
                    }),
                    function ($attribute, $value, $fail) use ($normalizedRow) {
                        if (
                            ($normalizedRow['allowance_type'] ?? null) === 'variable' &&
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
                        return ($normalizedRow['allowance_type'] ?? null) === 'variable';
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

            Allowances::create($data);
        }

        if (!empty($this->errors)) {
            $this->throwValidationException();
        }
    }

    private function normalizeRow($row)
    {
        $normalized = [];
        $mappings = [
            'allowance_code' => ['allowance_code', 'code', 'allowance code'],
            'allowance_name' => ['allowance_name', 'name', 'allowance name'],
            'status' => ['status'],
            'allowance_type' => ['allowance_type', 'type', 'allowance type'],
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

                    // Normalize empty strings to null
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
        if ($data['allowance_type'] === 'fixed') {
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

        // Excel serial date
        if (is_numeric($value)) {
            $unixDate = ((int) $value - 25569) * 86400;
            return gmdate('Y-m-d', $unixDate);
        }

        // String date
        try {
            $ts = strtotime((string) $value);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        } catch (\Throwable $e) {
            // fallthrough
        }

        return $value; // let validator catch invalid date
    }

    private function toNumeric($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return $value + 0; // cast to int/float
        }
        return $value;
    }

    private function resolveCompanyId($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        // If dropdown used, this will already be an ID (numeric)
        if (is_numeric($value)) {
            return (int) $value;
        }
        // Fallback: allow typing company name
        $name = trim((string) $value);
        $id = DB::table('companies')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        return $id ?: $value; // keep original so validator will fail with exists if not found
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
