<?php
namespace App\Imports;

use App\Models\deduction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeductionImport implements ToCollection, WithHeadingRow
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

            if (!isset($normalizedRow['deduction_type'])) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => ['Missing required column: deduction_type']
                ];
                continue;
            }

            // Convert Excel dates to proper format
            if (isset($normalizedRow['startDate']) && is_numeric($normalizedRow['startDate'])) {
                $normalizedRow['startDate'] = $this->convertExcelDate($normalizedRow['startDate']);
            }

            if (isset($normalizedRow['endDate']) && is_numeric($normalizedRow['endDate'])) {
                $normalizedRow['endDate'] = $this->convertExcelDate($normalizedRow['endDate']);
            }

            // Check if deduction_code already exists
            if (isset($normalizedRow['deduction_code']) && deduction::where('deduction_code', $normalizedRow['deduction_code'])->exists()) {
                continue; // Skip this row and continue with the next one
            }

            $validator = Validator::make($normalizedRow, [
                'deduction_code' => 'required',  // Removed unique validation since we're handling it above
                'deduction_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
              
                'deduction_type' => 'required|in:fixed,variable',
                'company_id' => 'required|exists:companies,id',
                'department_id' => [
                    'nullable',
                    'exists:departments,id',
                    Rule::exists('departments', 'id')->where(function ($query) use ($normalizedRow) {
                        $query->where('company_id', $normalizedRow['company_id']);
                    })
                ],
                'startDate' => 'required|date',
                'endDate' => [
                    'nullable',
                    'date',
                    Rule::requiredIf(function () use ($normalizedRow) {
                        return $normalizedRow['deduction_type'] === 'variable';
                    }),
                    function ($attribute, $value, $fail) use ($normalizedRow) {
                        if (
                            $normalizedRow['deduction_type'] === 'variable' &&
                            isset($normalizedRow['startDate']) &&
                            $value <= $normalizedRow['startDate']
                        ) {
                            $fail('End date must be after start date.');
                        }
                    }
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

            deduction::create($data);
        }

        if (!empty($this->errors)) {
            $this->throwValidationException();
        }
    }

    private function normalizeRow($row)
    {
        $normalized = [];
        $mappings = [
            'deduction_code' => ['deduction_code', 'code', 'deduction code'],
            'deduction_name' => ['deduction_name', 'name', 'deduction name'],
            'description' => ['description'],
            'amount' => ['amount'],
            'status' => ['status'],
          
            'deduction_type' => ['deduction_type', 'type', 'deduction type'],
            'company_id' => ['company_id', 'company', 'company id'],
            'department_id' => ['department_id', 'department', 'department id'],
            'startDate' => ['startDate', 'start date'],
            'endDate' => ['endDate', 'end date']
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
        if ($data['deduction_type'] === 'fixed') {
            $data['endDate'] = null;
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
