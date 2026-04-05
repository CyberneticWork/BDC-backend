<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\time_card;
use App\Models\employee;
use Illuminate\Support\Facades\DB;

class DinnerAllowanceController extends Controller
{
    // 1. 7.30 
    public function getEligibleEmployees(Request $request)
    {
        $date = $request->query('date', date('Y-m-d'));

        // Time cards 
        $query = "
            SELECT 
                tc.employee_id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                MIN(CASE WHEN tc.status IN ('IN', 'Late Coming') THEN tc.time END) as in_time,
                MAX(CASE WHEN tc.status IN ('OUT', 'Early OUT') THEN tc.time END) as out_time,
                da.status as approval_status,
                da.amount,
                da.id as allowance_id
            FROM time_cards tc
            JOIN employees e ON tc.employee_id = e.id
            LEFT JOIN dinner_allowances da ON tc.employee_id = da.employee_id AND tc.date = da.date
            WHERE tc.date = ? AND tc.deleted_at IS NULL
            GROUP BY tc.employee_id, e.attendance_employee_no, e.full_name, da.status, da.amount, da.id
            HAVING out_time >= '19:30:00' 
        ";

        $results = DB::select($query, [$date]);
        return response()->json($results);
    }

    // 2. Approve  or Reject
    public function processAllowance(Request $request)
    {
        $request->validate([
            'employee_id' => 'required',
            'date' => 'required|date',
            'status' => 'required|in:Approved,Rejected',
            'amount' => 'required|numeric'
        ]);

        //  Update 
        DB::table('dinner_allowances')->updateOrInsert(
            ['employee_id' => $request->employee_id, 'date' => $request->date],
            ['status' => $request->status, 'amount' => $request->amount, 'updated_at' => now()]
        );

        return response()->json(['message' => 'Processed Successfully']);
    }

    // 3. Monthly Employee Wise Report 
    public function getMonthlyReport(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $query = "
            SELECT 
                e.attendance_employee_no as emp_no,
                e.full_name,
                COUNT(da.id) as total_days,
                SUM(da.amount) as total_amount
            FROM dinner_allowances da
            JOIN employees e ON da.employee_id = e.id
            WHERE MONTH(da.date) = ? AND YEAR(da.date) = ? AND da.status = 'Approved'
            GROUP BY e.id, e.attendance_employee_no, e.full_name
        ";

        $results = DB::select($query, [$month, $year]);
        return response()->json($results);
    }
}