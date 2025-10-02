<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\NopayController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ApiDataController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\TimeCardController;
use App\Http\Controllers\DeductionController;
use App\Http\Controllers\AllowancesController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\LeaveMasterController;
use App\Http\Controllers\ResignationController;
use App\Http\Controllers\LeaveCalenderController;
use App\Http\Controllers\SalaryProcessController;
use App\Http\Controllers\SubDepartmentsController;
use App\Http\Controllers\LMSController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\PmsController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->get('/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->noContent();
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::put('/leave-masters/{id}/status', [LeaveMasterController::class, 'updateStatus']);
// Route::get('/test', [AuthController::class, 'test']);
Route::get('/dashboard/stats/today', [TimeCardController::class, 'getTodayStats']);
Route::apiResource('users', UserController::class);
Route::apiResource('shifts', ShiftController::class);
Route::apiResource('employees', EmployeeController::class);
Route::post('employes/post/update', [EmployeeController::class, 'update']);
Route::get('/emp/table', [EmployeeController::class, 'getEmployeesForTable']);
Route::get('/emp/search', [EmployeeController::class, 'search']);
Route::get('/emp/search/empno', [EmployeeController::class, 'searchByAttendanceNo']);
Route::apiResource('loans', LoanController::class);
Route::apiResource('allowances', AllowancesController::class);
Route::get('/allowance/by-company-or-department', [AllowancesController::class, 'getAllowancesByCompanyOrDepartment']);
Route::get('/deduction/by-company-or-department', [DeductionController::class, 'getDeductionsByCompanyOrDepartment']);
Route::get('/leave-masters/{employeeId}/counts', [LeaveMasterController::class, 'getLeaveRecordCountsByEmployee']);
Route::apiResource('deductions', DeductionController::class);
Route::apiResource('leave-calendars', LeaveCalenderController::class);
Route::apiResource('companies', CompanyController::class);
Route::apiResource('departments', DepartmentsController::class)->only(['store', 'update', 'destroy']);
Route::apiResource('subdepartments', SubDepartmentsController::class);
Route::apiResource('rosters', RosterController::class);
Route::apiResource('overtime', OvertimeController::class);
Route::post('/overtime/approve/{id}', [OvertimeController::class, 'approve']);
Route::apiResource('leave-masters', LeaveMasterController::class);
Route::apiResource('salary-process', SalaryProcessController::class);
Route::get('salary/processed', [SalaryProcessController::class, 'getProcessedSalaries']);
Route::post('/salary/process/mark-issued', [SalaryProcessController::class, 'markAsIssued']);
Route::post('/salary/process/fetchExcelData', [SalaryProcessController::class, 'fetchExcelData']);
Route::post('/salary/process/importExcelData', [SalaryProcessController::class, 'importExcelData']);
Route::get('/salary/update/status', [SalaryProcessController::class, 'updateSlaryStatus']);
// Route::apiResource('salary', SalaryController::class);
// Route::get('salary/{id}/audit', [SalaryController::class, 'getAuditLogs']);


Route::get('/Leave-Master/{employeeId}/counts', [LeaveMasterController::class, 'getLeaveRecordCountsByEmployee']);

Route::get('/Leave-Master/status/pending', [LeaveMasterController::class, 'getPendingLeaveRecords']);
Route::get('/Leave-Master/status/approved', [LeaveMasterController::class, 'getApprovedLeaveRecords']);
Route::get('/Leave-Master/status/hr-approved', [LeaveMasterController::class, 'getHRApprovedLeaveRecords']);


Route::get('/time-cards', [TimeCardController::class, 'index']);


Route::prefix('apiData')->group(function () {
    Route::get('/companies', [ApiDataController::class, 'companies']);
    Route::get('/departments', [ApiDataController::class, 'departments']);
    Route::get('/subDepartments', [ApiDataController::class, 'subDepartments']);
    Route::get('/designations', [ApiDataController::class, 'designations']);
    Route::get('/companies/{id}/employees', [ApiDataController::class, 'employeesByCompany']);
    Route::get('/companies/{id}', [ApiDataController::class, 'companiesById']);
    Route::get('/departments/{id}', [ApiDataController::class, 'departmentsById']);
    Route::get('/subDepartments/{id}', [ApiDataController::class, 'subDepartmentsById']);
    Route::get('/subDepartments/{id}/employees', [ApiDataController::class, 'employeesBySubDepartment']);
});

// Resignation routes
Route::get('/resignations', [ResignationController::class, 'index']);
Route::post('/resignations', [ResignationController::class, 'store']);
Route::get('/resignations/{id}', [ResignationController::class, 'show']);
Route::put('/resignations/{id}/status', [ResignationController::class, 'updateStatus']);

// Document routes
Route::post('/resignations/{id}/documents', [ResignationController::class, 'uploadDocuments']);
Route::delete('/resignations/{resignationId}/documents/{documentId}', [ResignationController::class, 'destroyDocument']);

//time card
Route::put('/time-cards/{id}', [TimeCardController::class, 'update']);
Route::delete('/time-cards/{id}', [TimeCardController::class, 'destroy']);
Route::get('/employees/by-nic/{nic}', [EmployeeController::class, 'getByNic']);
Route::post('/time-cards', [TimeCardController::class, 'store']);
Route::post('/attendance', [TimeCardController::class, 'attendance']);
// Route::post('/attendance/mark-absentees', [TimeCardController::class, 'markAbsentees']);
Route::get('/time-cards/search-employee', [TimeCardController::class, 'searchByEmployee']);
Route::post('/attendance/import-excel', [TimeCardController::class, 'importExcel']);
Route::get('/companies', [CompanyController::class, 'index']);
Route::get('/attendance/absentees', [TimeCardController::class, 'fetchAbsentees']);
Route::get('/attendance-template', [TimeCardController::class, 'downloadTemplate']);

//get employees by month and company
Route::get('/salaryCal/employees', [SalaryProcessController::class, 'getEmployeesByMonthAndCompany']);
Route::post('/salary/process/allowances', [SalaryProcessController::class, 'updateEmployeesAllowances']);
Route::post('/salary/process/save', [SalaryProcessController::class, 'storeSalaryData']);

Route::post('/attendance/mark-absentees', [TimeCardController::class, 'markAbsentees']);
Route::get('/absentees', [ApiDataController::class, 'Absentees']);
// No Pay routes
Route::get('no-pay-records', [NopayController::class, 'index']);
Route::post('no-pay-records', [NopayController::class, 'store']);
Route::put('no-pay-records/{id}', [NopayController::class, 'update']);
Route::post('no-pay-records/bulk-update', [NopayController::class, 'bulkUpdateStatus']);
Route::delete('no-pay-records/{id}', [NopayController::class, 'destroy']);
Route::delete('no-pay-records/bulk-delete', [NopayController::class, 'bulkDestroy']);
Route::post('no-pay-records/generate', [NopayController::class, 'generateDailyNoPayRecords']);
Route::get('no-pay-records/stats', [NopayController::class, 'getNoPayStats']);

// Allowances import/export routes
Route::get('/allowances/template/download', [AllowancesController::class, 'downloadTemplate']);
Route::post('/allowances/import', [AllowancesController::class, 'import']);
Route::get('/roster/search', [RosterController::class, 'search']);

// Deductions import/export routes
Route::get('/deductions/template/download', [DeductionController::class, 'downloadTemplate']);
Route::post('/deductions/import', [DeductionController::class, 'import']);
Route::get('/loans/employee-by-number/{number}', [LoanController::class, 'getEmployeeByNumber']);

Route::post('/test', [ResignationController::class, 'testFunction']);

Route::apiResource('salary', SalaryController::class);
Route::get('salary/{id}/audit', [SalaryController::class, 'getAuditLogs']);
Route::get('/salary/process/csv', [SalaryController::class, 'salaryCSV']);

// LMS Routes

Route::apiResource('courses', LMSController::class);  // Handles all CRUD: GET /courses (index), POST /courses (store), GET /courses/{id} (show), etc.


// Route::middleware('auth:sanctum')->group(function () {
Route::apiResource('exams', ExamController::class);
Route::post('exams/{id}/submit', [ExamController::class, 'submitExam']);
Route::get('exam-results', [ExamController::class, 'getResults']);
// });

// PMS Related Data Endpoints
Route::middleware(['auth:sanctum'])->group(function () {
    // PMS Related Data Endpoints - MOVED INSIDE AUTH
    Route::get('/kpi-tasks', [PmsController::class, 'getKpiTasks']);
    Route::get('/creator-roles', [PmsController::class, 'getCreatorRoles']);
    Route::get('/pms/companies', [PmsController::class, 'getCompanies']);
    Route::get('/pms/departments/{companyId}', [PmsController::class, 'getDepartmentsByCompany']);
    Route::get('/pms/employees-by-company', [PmsController::class, 'getEmployeesByCompany']);
    Route::get('/pms/search-employees', [PmsController::class, 'searchEmployeesByAttendanceNo']);
    Route::get('/pms/kpi-task-assignments', [PmsController::class, 'getKpiTaskAssignments']);
    Route::post('/pms/kpi-task-assignments', [PmsController::class, 'storeKpiTaskAssignment']);
    Route::put('/pms/kpi-task-assignments/{id}', [PmsController::class, 'updateKpiTaskAssignment']);
    Route::delete('/pms/kpi-task-assignments/{id}', [PmsController::class, 'destroy']);

    // PMS Performance Reviews
    Route::get('/pms/performance-reviews', [PmsController::class, 'getPerformanceReviews']);
    Route::get('/pms/performance-reviews/{assignmentId}/details', [PmsController::class, 'getPerformanceReviewDetails']);
    Route::get('/pms/performance-reviews/{assignmentId}/documents', [PmsController::class, 'getAssignmentDocuments']);
    Route::put('/pms/performance-reviews/{assignmentId}', [PmsController::class, 'updatePerformanceReview']);
    
    
    // Other PMS routes that require authentication
    Route::get('/pms/kpi-task-assignments/employee/{employeeId}', [PmsController::class, 'getEmployeeKpiTaskAssignments']);
    Route::post('/pms/task-progress-submissions', [PmsController::class, 'storeTaskProgressSubmission']);
    Route::get('/pms/task-progress-submissions/assignment/{assignmentId}', [PmsController::class, 'getTaskProgressSubmissions']);
    Route::get('/pms/task-progress-submissions/employee/{employeeId}', [PmsController::class, 'getEmployeeTaskProgressSubmissions']);
    
    // PMS Dashboard endpoints
    Route::get('/pms/dashboard/stats', [PmsController::class, 'getDashboardStats']);
    Route::get('/pms/dashboard/upcoming-deadlines', [PmsController::class, 'getUpcomingDeadlines']);
    Route::get('/pms/dashboard/KPIs', [PmsController::class, 'getKpiPerformance']);

    // KPI Tasks CRUD routes
    Route::post('/kpi-tasks', [PmsController::class, 'storeKpiTask']);
    Route::put('/kpi-tasks/{id}', [PmsController::class, 'updateKpiTask']);
    Route::delete('/kpi-tasks/{id}', [PmsController::class, 'destroyKpiTask']);

    // Creator Roles CRUD
    Route::post('/creator-roles', [PmsController::class, 'storeCreatorRole']);
    Route::put('/creator-roles/{id}', [PmsController::class, 'updateCreatorRole']);
    Route::delete('/creator-roles/{id}', [PmsController::class, 'destroyCreatorRole']);

    // Approval list (used by frontend TaskApproval)
    Route::get('/pms/kpi-task-assignments-for-approval', [PmsController::class, 'getKpiTaskAssignmentsForApproval']);

    // Employee-specific assignments (used by employee view)
    Route::get('/pms/employee-kpi-task-assignments/{employeeId}', [PmsController::class, 'getEmployeeKpiTaskAssignments']);

    // Approve / reject endpoints (POST)
    Route::post('/pms/kpi-tasks/{id}/approve', [PmsController::class, 'approveKpiTask']);
    Route::post('/pms/kpi-tasks/{id}/reject', [PmsController::class, 'rejectKpiTask']);

    // Practical Feedback routes
    Route::post('/pms/performance-reviews/{assignmentId}/practical-feedback', [PmsController::class, 'submitPracticalFeedback']);
    Route::get('/pms/practical-feedback/history', [PmsController::class, 'getPracticalFeedbackHistory']);
    Route::get('/pms/practical-feedback/stats', [PmsController::class, 'getFeedbackStats']);
});

// Employee Performance Evaluation endpoints (these can remain public if needed)
Route::post('/pms/employee-performance/calculate', [PmsController::class, 'calculateEmployeePerformance']);
Route::post('/pms/employee-performance/save', [PmsController::class, 'saveEmployeePerformance']);
Route::get('/pms/employee-performance', [PmsController::class, 'getEmployeePerformanceEvaluations']);
