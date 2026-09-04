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
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $normalizedRow = $this->normalizeRow($row);

            if (isset($normalizedRow['deduction_code']) && deduction::where('deduction_code', $normalizedRow['deduction_code'])->exists()) {
                continue;
            }

            $validator = Validator::make($normalizedRow, [
                'deduction_code' => 'required',
                'deduction_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'company_id' => 'required|exists:companies,id',
                'department_id' => [
                    'nullable',
                    'exists:departments,id',
                    Rule::exists('departments', 'id')->where(function ($query) use ($normalizedRow) {
                        $query->where('company_id', $normalizedRow['company_id']);
                    })
                ],
            ]);

            if ($validator->fails()) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => $validator->errors()->all()
                ];
                continue;
            }

            $data = $validator->validated();
            $data['startDate'] = null;
            $data['endDate'] = null;

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
            'company_id' => ['company_id', 'company', 'company id'],
            'department_id' => ['department_id', 'department', 'department id'],
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

    private function throwValidationException()
    {
        $errorMessages = [];
        foreach ($this->errors as $error) {
            $errorMessages[] = "Row {$error['row']}: " . implode(', ', $error['errors']);
        }
        throw new \Exception(implode("\n", $errorMessages));
    }
}
