<?php

namespace App\Models;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class time_card extends Model
{
    use SoftDeletes; // Add this trait
    
    protected $fillable = [
        'employee_id', 'time', 'date', 'working_hours', 'entry', 'status','fingerprint_clock','actual_date'
    ];

    public function employee()
    {
        return $this->belongsTo(employee::class, 'employee_id');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $path = $request->file('file')->getRealPath();
        $rows = Excel::toArray([], $path)[0];

        $results = [
            'imported' => 0,
            'absent' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                if ($index === 0) continue; // skip header row

                $nic = trim($row[0]);
                $name = trim($row[1]);
                $date = trim($row[2]);
                $time = trim($row[3]);
                $entry = trim($row[4]);
                $status = trim($row[5]);

                $identifier = trim($nic);
                $employee = employee::where(function ($q) use ($identifier) {
                    $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                      ->orWhere('attendance_employee_no', $identifier);
                })->first();
                if (!$employee) {
                    $results['errors'][] = "Row $index: Employee not found for NIC $nic";
                    continue;
                }

                if (in_array($status, ['IN', 'OUT', 'Leave'])) {
                    // Use same validation and roster logic as store()
                    $requestData = [
                        'employee_id' => $employee->id,
                        'time' => $time,
                        'date' => $date,
                        'entry' => $entry,
                        'status' => $status,
                    ];
                    $storeRequest = new Request($requestData);
                    $response = $this->store($storeRequest);
                    if ($response->getStatusCode() === 201) {
                        $results['imported']++;
                    } else {
                        $results['errors'][] = "Row $index: " . $response->getData()->message;
                    }
                } elseif ($status === 'Absent') {
                    // Save to absences table
                    absence::create([
                        'employee_id' => $employee->id,
                        'date' => $date,
                        'reason' => 'Imported from Excel',
                    ]);
                    $results['absent']++;
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }

        return response()->json($results);
    }
}
