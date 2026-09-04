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
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $normalizedRow = $this->normalizeRow($row);

            if (
                isset($normalizedRow['allowance_code']) &&
                allowances::where('allowance_code', $normalizedRow['allowance_code'])->exists()
            ) {
                continue;
            }

            if (isset($normalizedRow['status'])) {
                $normalizedRow['status'] = strtolower(trim((string) $normalizedRow['status']));
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

            $validator = Validator::make($normalizedRow, [
                'allowance_code' => 'required|string',
                'allowance_name' => 'required|string|max:255',
                'status' => ['required', Rule::in(['active', 'inactive'])],
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
            ]);

            if ($validator->fails()) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => $validator->errors()->all()
                ];
                continue;
            }

            $data = $validator->validated();
            $data['fixed_date'] = null;
            $data['variable_from'] = null;
            $data['variable_to'] = null;

            allowances::create($data);
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
            'company_id' => ['company_id', 'company', 'company id'],
            'department_id' => ['department_id', 'department', 'department id'],
            'amount' => ['amount'],
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
