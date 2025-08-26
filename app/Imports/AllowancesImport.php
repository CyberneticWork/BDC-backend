<?php
namespace App\Imports;

use App\Models\allowances;
use Illuminate\Support\Collection;
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

            if (!isset($normalizedRow['allowance_type'])) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => ['Missing required column: allowance_type']
                ];
                continue;
            }

            // Check if allowance_code already exists
            if (
                isset($normalizedRow['allowance_code']) &&
                Allowances::where('allowance_code', $normalizedRow['allowance_code'])->exists()
            ) {
                continue; // Skip this row and continue with the next one
            }

            // Convert Excel dates to proper format
            if (isset($normalizedRow['fixed_date']) && is_numeric($normalizedRow['fixed_date'])) {
                $normalizedRow['fixed_date'] = $this->convertExcelDate($normalizedRow['fixed_date']);
            }

            if (isset($normalizedRow['variable_from']) && is_numeric($normalizedRow['variable_from'])) {
                $normalizedRow['variable_from'] = $this->convertExcelDate($normalizedRow['variable_from']);
            }

            if (isset($normalizedRow['variable_to']) && is_numeric($normalizedRow['variable_to'])) {
                $normalizedRow['variable_to'] = $this->convertExcelDate($normalizedRow['variable_to']);
            }

            $validator = Validator::make($normalizedRow, [
                'allowance_code' => 'required',  // Removed unique validation since we're handling it above
                'allowance_name' => 'required|string|max:255',
                'status' => 'required|in:active,inactive',
              
                'allowance_type' => 'required|in:fixed,variable',
                'company_id' => 'required|exists:companies,id',
                'amount' => 'required|numeric|min:0',
                'department_id' => [
                    'nullable',
                    'exists:departments,id',
                    Rule::exists('departments', 'id')->where(function ($query) use ($normalizedRow) {
                        $query->where('company_id', $normalizedRow['company_id']);
                    })
                ],
                'fixed_date' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return $normalizedRow['allowance_type'] === 'fixed';
                    })
                ],
                'variable_from' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return $normalizedRow['allowance_type'] === 'variable';
                    }),
                    function ($attribute, $value, $fail) use ($normalizedRow) {
                        if (
                            $normalizedRow['allowance_type'] === 'variable' &&
                            isset($normalizedRow['variable_to']) &&
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
                        return $normalizedRow['allowance_type'] === 'variable';
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
                    $normalized[$field] = $row[$header];
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

    private function convertExcelDate($excelDate)
    {
        if (is_numeric($excelDate)) {
            // Convert Excel serial date to YYYY-MM-DD
            $unixDate = ($excelDate - 25569) * 86400;
            return gmdate("Y-m-d", $unixDate);
        }

        // Try to parse as date string if not numeric
        try {
            return date("Y-m-d", strtotime($excelDate));
        } catch (\Exception $e) {
            return $excelDate;
        }
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
