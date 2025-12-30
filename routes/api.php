<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\NopayController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\EnrollmentController;
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
use App\Http\Controllers\LMSAdmincontroller;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\PmsController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCategoryController;
use App\Http\Controllers\CustomerTypeController;
use App\Http\Controllers\PerformanceEvaluationController;
use App\Http\Controllers\PerformanceAppraisalController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\DiscountLevelController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CentersController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryProductController;
use App\Http\Controllers\InventoryStockController;


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
Route::apiResource('customers', CustomerController::class);

// Discount levels (index/show are public; create/update/delete require auth)

Route::get('/discount-levels/{id}', [DiscountLevelController::class, 'show']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/discount-levels', [DiscountLevelController::class, 'store']);
    Route::put('/discount-levels/{id}', [DiscountLevelController::class, 'update']);
    Route::delete('/discount-levels/{id}', [DiscountLevelController::class, 'destroy']);
});

Route::get('customer-categories', [CustomerCategoryController::class, 'index']);
Route::post('customer-categories', [CustomerCategoryController::class, 'store']);
Route::get('customer-types', [CustomerTypeController::class, 'index']);
Route::post('customer-types', [CustomerTypeController::class, 'store']);


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
    Route::post('/addNewDesignation', [ApiDataController::class, 'addNewDesignation']);
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

Route::apiResource('courses', LMSController::class);
Route::apiResource('accounts', AccountController::class);
Route::apiResource('journal-entries', JournalEntryController::class);
Route::get('journal-entries-next-number', [JournalEntryController::class, 'getNextEntryNumber']);
//Route::apiResource('account-groups', AccountGroupController::class);

Route::delete('/attachments/{id}', [LMSController::class, 'removeAttachment']);
Route::middleware('auth:sanctum')->group(function () {
    // Exam routes
    Route::apiResource('exams', ExamController::class);
    Route::post('exams/{id}/submit', [ExamController::class, 'submitExam']);
    Route::get('exam-results', [ExamController::class, 'getResults']);

    // Enrollment routes
    Route::get('enrollments', [EnrollmentController::class, 'index']);
    Route::post('courses/{courseId}/enroll', [EnrollmentController::class, 'enroll']);
    Route::delete('courses/{courseId}/enroll', [EnrollmentController::class, 'unenroll']);
    Route::get('courses/{courseId}/enrollment', [EnrollmentController::class, 'checkEnrollment']);
    Route::get('courses/{courseId}/progress', [EnrollmentController::class, 'getProgress']);
    Route::post('courses/{courseId}/modules/{moduleId}/progress', [EnrollmentController::class, 'updateModuleProgress']);
    Route::get('user/progress', [EnrollmentController::class, 'getUserProgress']);
});

// LMS Admin Dashboard Routes
Route::middleware('auth:sanctum')->prefix('admin/lms')->group(function () {
    Route::get('users/course-progress', [LMSAdmincontroller::class, 'listAllUsersCourseProgress']);
    Route::get('users/exam-progress', [LMSAdmincontroller::class, 'listAllUsersExamProgress']);
    Route::get('users/{userId}/course-progress', [LMSAdmincontroller::class, 'getUserCourseProgress']);
    Route::get('users/{userId}/exam-progress', [LMSAdmincontroller::class, 'getUserExamProgress']);
    Route::get('stats', [LMSAdmincontroller::class, 'getLmsStats']);
});

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

    // Notification routes
    Route::get('/notifications', [PmsController::class, 'getUserNotifications']);
    Route::post('/notifications/{notificationId}/read', [PmsController::class, 'markNotificationRead']);
    Route::post('/notifications/mark-all-read', [PmsController::class, 'markAllNotificationsRead']);
    Route::get('/notifications/unread-count', [PmsController::class, 'getUnreadCount']);
    Route::get('/pms/kpi-weights', [PmsController::class, 'getKpiWeights']);
    Route::post('/pms/kpi-weights', [PmsController::class, 'createKpiWeight']);
    Route::put('/pms/kpi-weights/{id}', [PmsController::class, 'updateKpiWeight']);
    Route::delete('/pms/kpi-weights/{id}', [PmsController::class, 'deleteKpiWeight']);
});




// Employee Performance Evaluation endpoints (these can remain public if needed)
Route::post('/pms/employee-performance/calculate', [PmsController::class, 'calculateEmployeePerformance']);
Route::post('/pms/employee-performance/save', [PmsController::class, 'saveEmployeePerformance']);
Route::get('/pms/employee-performance', [PmsController::class, 'getEmployeePerformanceEvaluations']);

// Add these routes in the authenticated section

Route::middleware('auth:sanctum')->group(function () {
    // ... existing routes ...

    // Performance Appraisal routes
    Route::post('/pms/performance-appraisal/calculate', [PmsController::class, 'calculatePerformanceAppraisal']);
    Route::post('/pms/performance-appraisal/save', [PmsController::class, 'savePerformanceAppraisal']);
    Route::get('/pms/performance-appraisals', [PmsController::class, 'getPerformanceAppraisals']);
});

// Performance Evaluation routes - using the new controller
Route::middleware('auth:sanctum')->group(function () {
    // CRUD operations
    Route::get('/performance-evaluations', [App\Http\Controllers\PerformanceEvaluationController::class, 'index']);
    Route::post('/performance-evaluations', [App\Http\Controllers\PerformanceEvaluationController::class, 'store']);
    Route::post('/performance-evaluations/bulk', [App\Http\Controllers\PerformanceEvaluationController::class, 'storeBulk']); // Add this line
    Route::get('/performance-evaluations/{id}', [App\Http\Controllers\PerformanceEvaluationController::class, 'show']);
    Route::put('/performance-evaluations/{id}', [App\Http\Controllers\PerformanceEvaluationController::class, 'update']);
    Route::delete('/performance-evaluations/{id}', [App\Http\Controllers\PerformanceEvaluationController::class, 'destroy']);

    // Additional functionality
    Route::get('/performance-evaluations/employee/{employeeId}', [App\Http\Controllers\PerformanceEvaluationController::class, 'getByEmployee']);

    // Stats, trash and restore/force-delete endpoints
    Route::get('/performance-evaluations/stats/overview', [App\Http\Controllers\PerformanceEvaluationController::class, 'getStats']);
    Route::get('/performance-evaluations/trashed/list', [App\Http\Controllers\PerformanceEvaluationController::class, 'getTrashed']);
    Route::post('/performance-evaluations/{id}/restore', [App\Http\Controllers\PerformanceEvaluationController::class, 'restore']);
    Route::delete('/performance-evaluations/{id}/force', [App\Http\Controllers\PerformanceEvaluationController::class, 'forceDestroy']);
});

// Performance Appraisal routes - using the new controller
Route::middleware('auth:sanctum')->group(function () {
    // CRUD operations
    Route::get('/performance-appraisals', [App\Http\Controllers\PerformanceAppraisalController::class, 'index']);
    Route::post('/performance-appraisals', [App\Http\Controllers\PerformanceAppraisalController::class, 'store']);
    Route::post('/performance-appraisals/bulk', [App\Http\Controllers\PerformanceAppraisalController::class, 'storeBulk']);
    Route::get('/performance-appraisals/{id}', [App\Http\Controllers\PerformanceAppraisalController::class, 'show']);
    Route::put('/performance-appraisals/{id}', [App\Http\Controllers\PerformanceAppraisalController::class, 'update']);
    Route::delete('/performance-appraisals/{id}', [App\Http\Controllers\PerformanceAppraisalController::class, 'destroy']);

    // Additional functionality
    Route::get('/performance-appraisals/employee/{employeeId}', [App\Http\Controllers\PerformanceAppraisalController::class, 'getByEmployee']);
    Route::get('/performance-appraisals/trashed/list', [App\Http\Controllers\PerformanceAppraisalController::class, 'getTrashed']);
    Route::post('/performance-appraisals/{id}/restore', [App\Http\Controllers\PerformanceAppraisalController::class, 'restore']);
    Route::delete('/performance-appraisals/{id}/force', [App\Http\Controllers\PerformanceAppraisalController::class, 'forceDestroy']);
    Route::get('/performance-appraisals/stats/overview', [App\Http\Controllers\PerformanceAppraisalController::class, 'getStats']);



});
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/pms/kpi-task-assignments/check-weights', [PmsController::class, 'checkAssigneeWeights']);


// Product routes
Route::get('products/inventory/details', [ProductController::class, 'inventoryDetails']);

//  Customer routes
Route::get('/customer/email/{email}', [CustomerController::class, 'getByEmail']);
Route::get('/customer/type/{typeId}', [CustomerController::class, 'getByType']);


// Product Type routes
Route::apiResource('product-types', ProductTypeController::class);
Route::post('product-types', [ProductTypeController::class, 'store']);
Route::post('product-types/{id}/restore', [ProductTypeController::class, 'restore']);
Route::post('product-types/{id}/status', [ProductTypeController::class, 'setStatus']);
Route::get('product-types/trashed/list', [ProductTypeController::class, 'getTrashed']);
Route::get('product-types/stats/overview', [ProductTypeController::class, 'getStats']);
Route::delete('product-types/{id}/force', [ProductTypeController::class, 'forceDestroy']);

// Inventory routes
Route::apiResource('products', ProductController::class);
Route::apiResource('inventories', InventoryController::class);
Route::get('/inventory-pending', [InventoryController::class, 'getPending']); // return all inventory records with status 'pending'
Route::post('/inventory-approved', [InventoryController::class, 'getApproved']); // return all inventory records with status 'approved'
Route::post('/grn', [InventoryController::class, 'store']);
Route::get('/grn/next', [InventoryController::class, 'nextGrn']);  //for next GRN number
Route::get('/grn', [InventoryController::class, 'listGrns']); //for get all GRNs
Route::get('/invoices/next', [InventoryController::class, 'nextInv']); //for next Invoice number
Route::get('/stock-transfer/next', [InventoryController::class, 'nextStockTransfer']); // next Stock Transfer number


Route::post('/salesOrder', [InventoryController::class, 'storeSalesOrder']);
Route::get('/salesOrder/next', [InventoryController::class, 'nextSalesOrder']); //for next Sales Order number
Route::get('/salesreturn/next', [InventoryController::class, 'nextSalesReturn']); //for next Sales Return number
Route::get('/invoices', [InventoryController::class, 'listInvoices']); //for get all invoices
Route::post('/invoices', [InventoryController::class, 'storeInvoice']);
Route::post('/salesreturn', [InventoryController::class, 'storeSalesReturn']);
Route::get('/salesreturn', [InventoryController::class, 'listSalesReturns']); //for get pending salesReturn
Route::post('/stock-transfer', [InventoryController::class, 'storeStockTransfer']);
Route::get('/purchaseOrder/next', [InventoryController::class, 'nextPurchaseOrder']); //for next Purchase Order number
Route::post('/purchaseOrder', [InventoryController::class, 'storePurchaseOrder']);
Route::get('/purchaseOrder', [InventoryController::class, 'listPurchaseOrders']);//for get all purchase orders
Route::get('/purchaseReturn/next', [InventoryController::class, 'nextPurchaseReturn']); //for next Purchase Return number
Route::post('/purchaseReturn', [InventoryController::class, 'storePurchaseReturn']);
Route::get('/stockVerification/next', [InventoryController::class, 'nextStockVerification']); // next Stock Verification number
Route::post('/stockVerification', [InventoryController::class, 'storeStockVerification']);
Route::apiResource('inventory-products', InventoryProductController::class);
Route::get('/inventory-stocks/all', [InventoryStockController::class, 'all']);
Route::get('products/inventory/details', [ProductController::class, 'inventoryDetails']);
Route::apiResource('centers', CentersController::class);

});




Route::get('/customer/name/{name}', [CustomerController::class, 'getByName']);
// Supplier routes
Route::apiResource('suppliers', SupplierController::class);
Route::get('/salesOrder', [InventoryController::class, 'listSalesOrders']); //for get all salesOrder
Route::get('/discount-levels', [DiscountLevelController::class, 'index']);
Route::get('/purchaseOrder', [InventoryController::class, 'listPurchaseOrders']);//for get all purchase orders
