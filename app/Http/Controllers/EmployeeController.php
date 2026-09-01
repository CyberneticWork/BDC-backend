<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\spouse;
use App\Models\children;
use App\Models\employee;
use App\Models\documents;
use App\Models\compensation;
use Illuminate\Http\Request;
use App\Models\contact_detail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeePasswordSendEmail;
use App\Models\organization_assignment;
use App\Services\EmployeeReportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    public function __construct(protected EmployeeReportService $employeeReportService)
    {
    }

    /**
     * Display a listing of the resource.
     */
    public function getByEmploymentType(string $typeName)
    {
        $employees = employee::with([
            'employmentType',
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.designation',
            'compensation',
        ])
            ->whereHas('employmentType', function ($q) use ($typeName) {
                $q->where('name', $typeName);
            })
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get();

        return response()->json($employees);
    }

    public function index()
    {
        $employees = employee::with([
            'employmentType',
            'spouse',
            'children',
            'contactDetail',
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.subDepartment',
            'organizationAssignment.designation',
        ])->get();
        return response()->json($employees, 200);
    }


    /*
    public function getEmployeesForTable(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search', '');

        $query = employee::with([
            'employmentType:id,name',
            'contactDetail:id,employee_id,email,mobile_line'
        ])->select([
                    'id',
                    'full_name',
                    'name_with_initials',
                    'profile_photo_path',
                    'epf',
                    'title',
                    'attendance_employee_no',
                    'is_active',
                    'employment_type_id',
                    'nic'
                ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhere('epf', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                    ->orWhere('nic', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
        */


    public function getEmployeesForTable(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search', '');

        $query = employee::with([
            'employmentType:id,name',
            'contactDetail:id,employee_id,email,mobile_line',
            'organizationAssignment.company:id,name',
            'organizationAssignment.department:id,name',
            'organizationAssignment.designation:id,name'
        ])->select([
            'id',
            'full_name',
            'name_with_initials',
            'profile_photo_path',
            'epf',
            'title',
            'attendance_employee_no',
            'is_active',
            'employment_type_id',
            'organization_assignment_id',
            'nic'
        ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                // (Name, EMP No, EPF, NIC)
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhere('epf', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                    ->orWhere('nic', 'like', "%{$search}%")

                    // (PERMANENT, Contract )
                    ->orWhereHas('employmentType', function ($typeQuery) use ($search) {
                        $typeQuery->where('name', 'like', "%{$search}%");
                    })

                    // 3. Company, Department Position (Designation)
                    ->orWhereHas('organizationAssignment', function ($orgQuery) use ($search) {
                        $orgQuery->whereHas('company', function ($companyQuery) use ($search) {
                            $companyQuery->where('name', 'like', "%{$search}%");
                        })
                            ->orWhereHas('department', function ($deptQuery) use ($search) {
                                $deptQuery->where('name', 'like', "%{$search}%");
                            })
                            ->orWhereHas('designation', function ($desigQuery) use ($search) {
                                $desigQuery->where('name', 'like', "%{$search}%");
                            });
                    });

                // 4. Status
                if (strtolower($search) === 'active') {
                    $q->orWhere('is_active', 1)->orWhere('is_active', true);
                } elseif (strtolower($search) === 'inactive') {
                    $q->orWhere('is_active', 0)->orWhere('is_active', false);
                }
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function search(Request $request)
    {
        $search = $request->input('search', '');

        $query = employee::query()
            ->select([
                'id',
                'full_name',
                'name_with_initials',
                'profile_photo_path',
                'epf',
                'attendance_employee_no',
                'nic'
            ])
            ->limit(10);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhere('epf', 'like', "%{$search}%")
                    ->orWhere('attendance_employee_no', 'like', "%{$search}%")
                    ->orWhere('nic', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    // Add this to your controller
    public function searchByAttendanceNo(Request $request)
    {
        $attendanceNo = $request->input('attendance_no');

        if (!$attendanceNo) {
            return response()->json(['error' => 'Attendance number is required'], 400);
        }

        $employee = employee::with([
            'employmentType',
            'spouse',
            'children',
            'contactDetail',
            'compensation',
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.subDepartment',
            'organizationAssignment.designation',
        ])
            ->where('attendance_employee_no', $attendanceNo)
            ->first();


        return response()->json($employee, 200);
    }




    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'profile_picture' => 'nullable|image|max:2048',
            'personal' => 'required|json',
            'address' => 'required|json',
            'compensation' => 'required|json',
            'organization' => 'required|json',
            'documents.*' => 'nullable|file|max:5120'
        ]);

        // Decode JSON data
        $personal = json_decode($request->input('personal'), true);
        $address = json_decode($request->input('address'), true);
        $compensation = json_decode($request->input('compensation'), true);
        $organization = json_decode($request->input('organization'), true);

        // Validate the decoded arrays
        $validator->after(function ($validator) use ($personal, $address, $compensation, $organization) {
            // Validate personal data
            $personalValidator = Validator::make($personal, [
                'title' => 'required|string|max:10',
                'attendanceEmpNo' => 'required|string|max:50|unique:employees,attendance_employee_no',
                'epfNo' => 'required|string|max:50|unique:employees,epf',
                'nicNumber' => [
                    'required',
                    'string',
                    'max:13',
                    function ($attribute, $value, $fail) {
                        $nic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value));
                        if (!(preg_match('/^[0-9]{9}[VX]$/', $nic) || preg_match('/^[0-9]{12}$/', $nic))) {
                            $fail('The ' . $attribute . ' is not a valid Sri Lankan NIC number.');
                        }
                        if (strlen($nic) === 12) {
                            $year = substr($nic, 0, 4);
                            if ($year < 1900 || $year > date('Y')) {
                                $fail('The ' . $attribute . ' has an invalid year.');
                            }
                        }
                    },
                ],
                'dob' => 'required|date',
                'gender' => 'required|in:Male,Female,Other',
                'religion' => 'nullable|string|max:50',
                'countryOfBirth' => 'nullable|string|max:100',
                'employmentStatus' => 'required',
                'nameWithInitial' => 'required|string|max:100',
                'fullName' => 'required|string|max:100',
                'displayName' => 'required|string|max:100',
                'maritalStatus' => 'required|in:Single,Married,Divorced,Widowed',
                'relationshipType' => 'nullable|string|max:20',
                'spouseTitle' => 'nullable|string|max:20',
                'spouseName' => 'nullable|string|max:100',
                'spouseAge' => 'nullable|numeric|min:18|max:100',
                'spouseDob' => 'nullable|date',
                'spouseNic' => [
                    'nullable',
                    'string',
                    'max:13',
                    function ($attribute, $value, $fail) {
                        if (empty($value)) return; //null type
                        $nic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value));
                        if (!(preg_match('/^[0-9]{9}[VX]$/', $nic) || preg_match('/^[0-9]{12}$/', $nic))) {
                            $fail('The ' . $attribute . ' is not a valid Sri Lankan NIC number.');
                        }
                        if (strlen($nic) === 12) {
                            $year = substr($nic, 0, 4);
                            if ($year < 1900 || $year > date('Y')) {
                                $fail('The ' . $attribute . ' has an invalid year.');
                            }
                        }
                    },
                ],
                'children' => 'nullable|array',
                'children.*.name' => 'nullable|string|max:100',
                'children.*.age' => 'required_if:children.*.name,!=,null|nullable|integer|min:0|max:100',
                'children.*.dob' => 'required_if:children.*.name,!=,null|nullable|date',
                'children.*.nic' => 'nullable|string|max:20',
            ]);

            // Validate address data
            $addressValidator = Validator::make($address, [
                'permanentAddress' => 'required|string|max:255',
                'temporaryAddress' => 'nullable|string|max:255',
                'email' => 'required|email',
                'landLine' => 'nullable|string|max:20',
                'mobileLine' => 'required|string|max:20',
                'gnDivision' => 'nullable|string|max:100',
                'policeStation' => 'nullable|string|max:100',
                'district' => 'required|string|max:100',
                'province' => 'required|string|max:100',
                'electoralDivision' => 'nullable|string|max:100',
                'emergencyContact.relationship' => 'required|string|max:50',
                'emergencyContact.contactName' => 'required|string|max:100',
                // 'emergencyContact.contactAddress' => 'required|string|max:255',
                'emergencyContact.contactTel' => 'required|string|max:20',
            ]);

            $compensationValidator = Validator::make($compensation, [
                'basicSalary' => 'required|numeric',
                'incrementValue' => 'nullable|numeric',
                'incrementEffectiveFrom' => 'nullable|date',
                'bankName' => 'nullable|string|max:100',
                'branchName' => 'nullable|string|max:100',
                'bankCode' => 'nullable|string|max:50',
                'branchCode' => 'nullable|string|max:50',
                'bankAccountNo' => 'nullable|string|max:50',
                'accountHolderName' => 'nullable|string|max:150',
                'comments' => 'nullable|string|max:255',
                'secondaryEmp' => 'required|boolean',
                'primaryEmploymentBasic' => 'required|boolean',
                'enableEpfEtf' => 'required|boolean',
                'otActive' => 'required|boolean',
                'earlyDeduction' => 'required|boolean',
                'incrementActive' => 'required|boolean',
                'nopayActive' => 'required|boolean',
                'morningOt' => 'required|boolean',
                'eveningOt' => 'required|boolean',
                'ot_morning_rate' => 'nullable|numeric',
                'ot_night_rate' => 'nullable|numeric',
                'budgetaryReliefAllowance2015' => 'required|boolean',
                'budgetaryReliefAllowance2016' => 'required|boolean',
                'stamp' => 'required|boolean',
            ]);

            $organizationValidator = Validator::make($organization, [
                'company' => 'required|integer|exists:companies,id',
                'department' => 'nullable|integer|exists:departments,id',
                'subDepartment' => 'nullable|integer|exists:sub_departments,id',
                'currentSupervisor' => 'nullable|string|max:100',
                'dateOfJoined' => 'required|date',
                'designation' => 'required|integer|exists:designations,id',
                'probationPeriod' => 'required|boolean',
                'trainingPeriod' => 'required|boolean',
                'contractPeriod' => 'required|boolean',
                'probationFrom' => 'nullable|date',
                'probationTo' => 'nullable|date',
                'trainingFrom' => 'nullable|date',
                'trainingTo' => 'nullable|date',
                'contractFrom' => 'nullable|date',
                'contractTo' => 'nullable|date|after_or_equal:contractFrom',
                'confirmationDate' => 'nullable|date',
                'resignationDate' => 'nullable|date',
                'resignationLetter' => 'nullable',
                'resignationApproved' => 'required|boolean',
                'currentStatus' => 'required|boolean',
                'dayOff' => 'nullable|string',
                'employeeCategory' => 'required|string|in:Executive,Non-Executive'
            ]);

            /*
            // Add custom validation for NIC uniqueness
            $employeeNic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $personal['nicNumber']));
            $spouseNic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $personal['spouseNic']));

            if ($employeeNic === $spouseNic) {
                $validator->errors()->add("personal.spouseNic", "Spouse NIC cannot be the same as employee NIC.");
            }
             */


            // Add custom validation for NIC uniqueness (SAFE)
            $employeeNic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $personal['nicNumber'] ?? ''));

            $spouseNicRaw = $personal['spouseNic'] ?? null;
            $spouseNic = $spouseNicRaw
                ? strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $spouseNicRaw))
                : null;

            if ($spouseNic && $employeeNic === $spouseNic) {
                $validator->errors()->add("personal.spouseNic", "Spouse NIC cannot be the same as employee NIC.");
            }
            /*
            // Validate Spouse DOB vs Age
            if (isset($personal['spouseDob']) && isset($personal['spouseAge'])) {
                $spouseDob = Carbon::parse($personal['spouseDob']);
                $calculatedSpouseAge = $spouseDob->diffInYears(Carbon::now());

                $calculatedSpouseAge = (int) floor($calculatedSpouseAge);

                if ($personal['spouseAge'] != $calculatedSpouseAge) {
                    $validator->errors()->add("personal.spouseAge", "Entered Spouse Age does not match Date of Birth.");
                }
            }
                */




            // Validate Spouse DOB vs Age (හිස් නැත්නම් විතරක් චෙක් කරන්න)
            if (!empty($personal['spouseDob']) && !empty($personal['spouseAge'])) {
                try {
                    $spouseDob = Carbon::parse($personal['spouseDob']);
                    $calculatedSpouseAge = (int) floor($spouseDob->diffInYears(Carbon::now()));

                    if ((int)$personal['spouseAge'] !== $calculatedSpouseAge) {
                        $validator->errors()->add("personal.spouseAge", "Entered Spouse Age does not match Date of Birth.");
                    }
                } catch (\Exception $e) {
                    // Date format
                    $validator->errors()->add("personal.spouseDob", "Invalid Date of Birth format.");
                }
            }

            /// Validate Children DOB vs Age for each child
            if (isset($personal['children']) && is_array($personal['children'])) {
                foreach ($personal['children'] as $index => $child) {
                    if (!empty($child['name']) && isset($child['dob']) && isset($child['age'])) {
                        $childDob = Carbon::parse($child['dob']);
                        $calculatedChildAge = $childDob->diffInYears(Carbon::now());

                        // Use floor() to get the completed years
                        $calculatedChildAge = (int) floor($calculatedChildAge);

                        if ($child['age'] != $calculatedChildAge) {
                            $validator->errors()->add("personal.children.$index.age", "Entered Age for child '{$child['name']}' does not match Date of Birth.");
                        }
                    }
                }
            }

            // Add errors to main validator
            foreach ($personalValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("personal.$key", $message);
                }
            }

            foreach ($addressValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("address.$key", $message);
                }
            }

            foreach ($compensationValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("compensation.$key", $message);
                }
            }

            foreach ($organizationValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("organization.$key", $message);
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        $profilePicturePath = null;
        try {
            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')->store('employee/profile_pictures', 'public');
                $personal['profile_picture_path'] = $profilePicturePath;
            }

            // Create spouse record

            if ($request->has('personal.relationshipType')) {
                $spouse = spouse::create([
                    'type' => $personal['relationshipType'],
                    'title' => $personal['spouseTitle'],
                    'name' => $personal['spouseName'],
                    'nic' => $personal['spouseNic'],
                    'age' => $personal['spouseAge'],
                    'dob' => $personal['spouseDob'],
                ]);
            } else {
                $spouse = null;
            }

            // Create organization assignment
            $orgAssignment = organization_assignment::create([
                'company_id' => $organization['company'],
                'department_id' => !empty($organization['department']) ? $organization['department'] : null,
                'sub_department_id' => !empty($organization['subDepartment']) ? $organization['subDepartment'] : null,
                'designation_id' => $organization['designation'],
                'current_supervisor' => $organization['currentSupervisor'] ?? null,
                'date_of_joining' => $organization['dateOfJoined'],
                'day_off' => $organization['dayOff'],
                'confirmation_date' => empty($organization['confirmationDate']) ? null : $organization['confirmationDate'],
                'probationary_period' => $organization['probationPeriod'],
                'training_period' => $organization['trainingPeriod'],
                'contract_period' => $organization['contractPeriod'],
                'probationary_period_from' => empty($organization['probationFrom']) ? null : $organization['probationFrom'],
                'probationary_period_to' => empty($organization['probationTo']) ? null : $organization['probationTo'],
                'training_period_from' => empty($organization['trainingFrom']) ? null : $organization['trainingFrom'],
                'training_period_to' => empty($organization['trainingTo']) ? null : $organization['trainingTo'],
                'contract_period_from' => empty($organization['contractFrom']) ? null : $organization['contractFrom'],
                'contract_period_to' => empty($organization['contractTo']) ? null : $organization['contractTo'],
                'date_of_resigning' => empty($organization['confirmationDate']) ? null : $organization['confirmationDate'],
                'is_active' => $organization['currentStatus'],
            ]);

            // Create employee record
            $employee = employee::create([
                'title' => $personal['title'],
                'attendance_employee_no' => $personal['attendanceEmpNo'],
                'epf' => $personal['epfNo'],
                'nic' => $personal['nicNumber'],
                'dob' => $personal['dob'],
                'gender' => strtolower($personal['gender']),
                'religion' => $personal['religion'] ?? null,
                'country_of_birth' => $personal['countryOfBirth'] ?? null,
                'name_with_initials' => $personal['nameWithInitial'],
                'full_name' => $personal['fullName'],
                'display_name' => $personal['displayName'],
                'marital_status' => strtolower($personal['maritalStatus']),
                'is_active' => true,
                'employment_type_id' => $personal['employmentStatus'],
                'organization_assignment_id' => $orgAssignment->id,
                'spouse_id' => $spouse ? $spouse->id : null,
                'profile_photo_path' => $profilePicturePath,
                'email' => $address['email'],
            ]);

            // Use NIC as password
            $plainPassword = $personal['nicNumber'];
            $hashedPassword = Hash::make($plainPassword);

            $user = User::create([
                'name' => $personal['fullName'],
                'email' => $address['email'],
                'nic' => $personal['nicNumber'],
                'employee_id' => $employee->id,
                'password' => $hashedPassword,
                'role' => 'employee',
            ]);

            // Send password via email
            try {
                Mail::to($address['email'])->send(new EmployeePasswordSendEmail($personal['fullName'], $address['email'], $plainPassword));
            } catch (\Exception $e) {
                // Log email error but don't fail the employee creation
                Log::error('Failed to send password email: ' . $e->getMessage());
            }

            // Create children records if any valid children exist
            if (isset($personal['children']) && is_array($personal['children'])) {
                foreach ($personal['children'] as $child) {
                    // Skip if name is empty (invalid child)
                    if (empty($child['name'])) {
                        continue;
                    }

                    // Validate child age and dob
                    if (!isset($child['age']) || !is_numeric($child['age']) || $child['age'] < 0 || $child['age'] > 100) {
                        continue;
                    }

                    if (!isset($child['dob']) || !strtotime($child['dob'])) {
                        continue;
                    }

                    children::create([
                        'employee_id' => $employee->id,
                        'name' => $child['name'],
                        'age' => (int) $child['age'],
                        'dob' => $child['dob'],
                        'nic' => empty($child['nic'] ?? null) ? null : $child['nic'],
                    ]);
                }
            }

            // Handle document uploads if any
            if ($request->hasFile('documents')) {
                // Get the documents metadata from the request
                $documentsMeta = json_decode($request->input('documents'), true) ?? [];

                foreach ($request->file('documents') as $index => $document) {
                    $path = $document->store('employee/documents', 'public');

                    // Extract the document type (e.g., "nid") from the metadata
                    $documentType = $documentsMeta[$index]['type'] ?? 'unknown'; // Fallback to 'unknown' if not provided

                    documents::create([
                        'employee_id' => $employee->id,
                        'document_type' => $documentType, // Will be "nid" in your case
                        'document_path' => $path,
                        'document_name' => $document->getClientOriginalName(),
                    ]);
                }
            }

            // Create contact details
            contact_detail::create([
                'employee_id' => $employee->id,
                'permanent_address' => $address['permanentAddress'],
                'temporary_address' => $address['temporaryAddress'] ?? null,
                'email' => $address['email'],
                'land_line' => $address['landLine'] ?? null,
                'mobile_line' => $address['mobileLine'] ?? null,
                'gn_division' => $address['gnDivision'] ?? null,
                'police_station' => $address['policeStation'] ?? null,
                'district' => $address['district'],
                'province' => $address['province'],
                'electoral_division' => $address['electoralDivision'] ?? null,
                'emg_relationship' => $address['emergencyContact']['relationship'],
                'emg_name' => $address['emergencyContact']['contactName'],
                'emg_address' => $address['emergencyContact']['contactAddress'],
                'emg_tel' => $address['emergencyContact']['contactTel'],
            ]);

            // Create compensation record
            compensation::create([
                'employee_id' => $employee->id,
                'basic_salary' => $compensation['basicSalary'],
                'increment_value' => $compensation['incrementValue'] ?? null,
                'increment_effected_date' => empty($organization['incrementEffectiveFrom']) ? null : $organization['incrementEffectiveFrom'],
                'bank_name' => $compensation['bankName'] ?? null,
                'branch_name' => $compensation['branchName'] ?? null,
                'bank_code' => $compensation['bankCode'] ?? null,
                'branch_code' => $compensation['branchCode'] ?? null,
                'bank_account_no' => $compensation['bankAccountNo'] ?? null,
                'account_holder_name' => $compensation['accountHolderName'] ?? null,
                'comments' => $compensation['comments'] ?? null,
                'secondary_emp' => $compensation['secondaryEmp'],
                'primary_emp_basic' => $compensation['primaryEmploymentBasic'],
                'enable_epf_etf' => $compensation['enableEpfEtf'],
                'ot_active' => $compensation['otActive'],
                'early_deduction' => $compensation['earlyDeduction'],
                'increment_active' => $compensation['incrementActive'],
                'active_nopay' => $compensation['nopayActive'],
                'ot_morning' => $compensation['morningOt'],
                'ot_evening' => $compensation['eveningOt'],
                'ot_morning_rate' => $compensation['ot_morning_rate'],
                'ot_night_rate' => $compensation['ot_night_rate'],
                'br1' => $compensation['budgetaryReliefAllowance2015'],
                'br2' => $compensation['budgetaryReliefAllowance2016'],
                'stamp' => $compensation['stamp'],
                'employee_category' => $organization['employeeCategory'],
                'monthly_bonus' => $compensation['monthlyBonus'] ?? 0,
                'sports_fund_percentage' => $compensation['sportsFundPercentage'] ?? null,
                'staff_fund_amount' => $compensation['staffFundAmount'] ?? 0,
            ]);

            // Add default roster
            // $employee->rosters()->create([
            //     'roster_id' => Carbon::now()->timestamp,
            //     'shift_code' => 1,
            //     'company_id' => $organization['company'],
            //     'employee_id' => $employee->id,
            //     'is_reccurring' => true,
            //     'reccurence_pattern' => 'annualy',
            //     'date_from' => $organization['dateOfJoined'],
            // ]);

            DB::commit();

            return response()->json([
                'message' => 'Employee created successfully',
                'employee_id' => $employee->id
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($profilePicturePath && Storage::disk('public')->exists($profilePicturePath)) {
                Storage::disk('public')->delete($profilePicturePath);
            }

            Log::error('Employee creation error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'sql' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Employee creation failed',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    private function generateStrongPassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $employee = employee::with([
            'employmentType',
            'spouse',
            'children',
            'contactDetail',
            'compensation',
            'documents',
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.subDepartment',
            'organizationAssignment.designation',
            'overtimes',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Employee details fetched successfully',
            'data' => $employee
        ], 200);
    }

    /**
     * Export employee data as structured JSON for PDF/CSV generation
     */
    public function export(Request $request)
    {
        $employeeId = $request->query('employee_id');

        $relations = [
            'employmentType',
            'spouse',
            'children',
            'contactDetail',
            'compensation',
            'documents',
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.subDepartment',
            'organizationAssignment.designation',
        ];

        if ($employeeId) {
            $employee = employee::with($relations)->findOrFail($employeeId);
            return response()->json(['data' => [$this->formatEmployeeExport($employee)]]);
        }

        $employees = employee::with($relations)->where('is_active', true)->orderBy('full_name')->get();

        return response()->json([
            'data' => $employees->map(fn ($emp) => $this->formatEmployeeExport($emp)),
        ]);
    }

    /**
     * Comprehensive employee report (personal, org, compensation, allowances, deductions, loans, etc.)
     */
    public function report(Request $request)
    {
        $employeeId = $request->query('employee_id') ? (int) $request->query('employee_id') : null;
        $employeeNo = $request->query('employee_no');
        $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;
        $departmentId = $request->query('department_id') ? (int) $request->query('department_id') : null;
        $activeOnly = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOLEAN);

        if ($employeeNo && !$employeeId) {
            $found = employee::where('attendance_employee_no', $employeeNo)->first();
            if (!$found) {
                return response()->json(['message' => 'Employee not found'], 404);
            }
            $employeeId = $found->id;
        }

        if ($employeeId && !employee::find($employeeId)) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $report = $this->employeeReportService->buildReport(
            $employeeId,
            $employeeNo,
            $companyId,
            $departmentId,
            $activeOnly
        );

        return response()->json($report);
    }

    private function formatEmployeeExport(employee $employee): array
    {
        $org = $employee->organizationAssignment;
        $contact = $employee->contactDetail;
        $comp = $employee->compensation;

        return [
            'id' => $employee->id,
            'employee_no' => $employee->attendance_employee_no,
            'epf_no' => $employee->epf,
            'nic' => $employee->nic,
            'full_name' => $employee->full_name,
            'name_with_initials' => $employee->name_with_initials,
            'title' => $employee->title,
            'gender' => $employee->gender,
            'dob' => $employee->dob,
            'marital_status' => $employee->marital_status,
            'email' => $employee->email ?? $contact?->email,
            'mobile' => $contact?->mobile_line,
            'permanent_address' => $contact?->permanent_address,
            'employment_type' => $employee->employmentType?->name,
            'company' => $org?->company?->name,
            'department' => $org?->department?->name,
            'sub_department' => $org?->subDepartment?->name,
            'designation' => $org?->designation?->name,
            'date_of_joining' => $org?->date_of_joining,
            'basic_salary' => $comp?->basic_salary,
            'monthly_bonus' => $comp?->monthly_bonus,
            'sports_fund_percentage' => $comp?->sports_fund_percentage,
            'staff_fund_amount' => $comp?->staff_fund_amount,
            'bank_name' => $comp?->bank_name,
            'bank_account_no' => $comp?->bank_account_no,
            'account_holder_name' => $comp?->account_holder_name,
            'enable_epf_etf' => $comp?->enable_epf_etf,
            'spouse_name' => $employee->spouse?->name,
            'children_count' => $employee->children?->count() ?? 0,
            'emergency_contact' => $contact?->emg_name,
            'emergency_phone' => $contact?->emg_tel,
            'status' => $employee->is_active ? 'Active' : 'Inactive',
        ];
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'profile_picture' => 'nullable|image|max:2048',
            'personal' => 'required|json',
            'address' => 'required|json',
            'compensation' => 'required|json',
            'organization' => 'required|json',
            'documents.*' => 'nullable|file|max:5120'
        ]);

        // Decode JSON data
        $personal = json_decode($request->input('personal'), true);
        $address = json_decode($request->input('address'), true);
        $compensation = json_decode($request->input('compensation'), true);
        $organization = json_decode($request->input('organization'), true);

        // Find the existing employee
        $employee = employee::findOrFail($personal['id']);

        // Validate the decoded arrays
        $validator->after(function ($validator) use ($personal, $address, $compensation, $organization, $employee) {
            // Validate personal data
            $personalValidator = Validator::make($personal, [
                'title' => 'required|string|max:10',
                'attendanceEmpNo' => 'required|string|max:50|unique:employees,attendance_employee_no,' . $employee->id,
                'epfNo' => 'required|string|max:50|unique:employees,epf,' . $employee->id,
                'nicNumber' => [
                    'required',
                    'string',
                    'max:13',
                    function ($attribute, $value, $fail) {
                        $nic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value));
                        if (!(preg_match('/^[0-9]{9}[VX]$/', $nic) || preg_match('/^[0-9]{12}$/', $nic))) {
                            $fail('The ' . $attribute . ' is not a valid Sri Lankan NIC number.');
                        }
                        if (strlen($nic) === 12) {
                            $year = substr($nic, 0, 4);
                            if ($year < 1900 || $year > date('Y')) {
                                $fail('The ' . $attribute . ' has an invalid year.');
                            }
                        }
                    },
                ],
                'dob' => 'required|date',
                'gender' => 'required|in:Male,Female,Other',
                'religion' => 'nullable|string|max:50',
                'countryOfBirth' => 'nullable|string|max:100',
                'employmentStatus' => 'required',
                'nameWithInitial' => 'required|string|max:100',
                'fullName' => 'required|string|max:100',
                'displayName' => 'required|string|max:100',
                'maritalStatus' => 'required|in:Single,Married,Divorced,Widowed',
                'relationshipType' => 'nullable|string|max:20',
                'spouseTitle' => 'nullable|string|max:20',
                'spouseName' => 'nullable|string|max:100',
                'spouseAge' => 'nullable|numeric|min:18|max:100',
                'spouseDob' => 'nullable|date',
                'spouseNic' => [
                    'nullable',
                    'string',
                    'max:13',
                    function ($attribute, $value, $fail) {
                        if (empty($value)) return;
                        $nic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value));
                        if (!(preg_match('/^[0-9]{9}[VX]$/', $nic) || preg_match('/^[0-9]{12}$/', $nic))) {
                            $fail('The ' . $attribute . ' is not a valid Sri Lankan NIC number.');
                        }
                        if (strlen($nic) === 12) {
                            $year = substr($nic, 0, 4);
                            if ($year < 1900 || $year > date('Y')) {
                                $fail('The ' . $attribute . ' has an invalid year.');
                            }
                        }
                    },
                ],
                'children' => 'nullable|array',
                'children.*.name' => 'nullable|string|max:100',
                'children.*.age' => 'required_if:children.*.name,!=,null|nullable|integer|min:0|max:100',
                'children.*.dob' => 'required_if:children.*.name,!=,null|nullable|date',
                'children.*.nic' => 'nullable|string|max:20',
            ]);

            // Validate address data
            $addressValidator = Validator::make($address, [
                'permanentAddress' => 'required|string|max:255',
                'temporaryAddress' => 'nullable|string|max:255',
                'email' => 'required|email|unique:contact_details,email,' . $employee->contactDetail?->id,
                'password' => 'nullable|string|min:8',
                'landLine' => 'nullable|string|max:20',
                'mobileLine' => 'required|string|max:20|unique:contact_details,mobile_line,' . $employee->contactDetail?->id,
                'gnDivision' => 'nullable|string|max:100',
                'policeStation' => 'nullable|string|max:100',
                'district' => 'required|string|max:100',
                'province' => 'required|string|max:100',
                'electoralDivision' => 'nullable|string|max:100',
                'emergencyContact.relationship' => 'required|string|max:50',
                'emergencyContact.contactName' => 'required|string|max:100',
                'emergencyContact.contactAddress' => 'required|string|max:255',
                'emergencyContact.contactTel' => 'required|string|max:20',
            ]);

            $compensationValidator = Validator::make($compensation, [
                'basicSalary' => 'required|numeric',
                'incrementValue' => 'nullable|numeric',
                'incrementEffectiveFrom' => 'nullable|date',
                'bankName' => 'nullable|string|max:100',
                'branchName' => 'nullable|string|max:100',
                'bankCode' => 'nullable|string|max:50',
                'branchCode' => 'nullable|string|max:50',
                'bankAccountNo' => 'nullable|string|max:50',
                'accountHolderName' => 'nullable|string|max:150',
                'comments' => 'nullable|string|max:255',
                'secondaryEmp' => 'required|boolean',
                'primaryEmploymentBasic' => 'required|boolean',
                'enableEpfEtf' => 'required|boolean',
                'otActive' => 'required|boolean',
                'earlyDeduction' => 'required|boolean',
                'incrementActive' => 'required|boolean',
                'nopayActive' => 'required|boolean',
                'morningOt' => 'required|boolean',
                'eveningOt' => 'required|boolean',
                'ot_morning_rate' => 'nullable|numeric',
                'ot_night_rate' => 'nullable|numeric',
                'budgetaryReliefAllowance2015' => 'required|boolean',
                'budgetaryReliefAllowance2016' => 'required|boolean',
                'stamp' => 'required|boolean',
            ]);

            $organizationValidator = Validator::make($organization, [
                'company' => 'required|integer|exists:companies,id',
                'department' => 'nullable|integer|exists:departments,id',
                'subDepartment' => 'nullable|integer|exists:sub_departments,id',
                'currentSupervisor' => 'nullable|string|max:100',
                'dateOfJoined' => 'required|date',
                'designation' => 'required|integer|exists:designations,id',
                'probationPeriod' => 'required|boolean',
                'trainingPeriod' => 'required|boolean',
                'contractPeriod' => 'required|boolean',
                'probationFrom' => 'nullable|date',
                'probationTo' => 'nullable|date',
                'trainingFrom' => 'nullable|date',
                'trainingTo' => 'nullable|date',
                'contractFrom' => 'nullable|date',
                'contractTo' => 'nullable|date|after_or_equal:contractFrom',
                'confirmationDate' => 'nullable|date',
                'resignationDate' => 'nullable|date',
                'resignationLetter' => 'nullable',
                'resignationApproved' => 'required|boolean',
                'currentStatus' => 'required|boolean',
                'dayOff' => 'nullable|string',
                'employeeCategory' => 'required|string|in:Executive,Non-Executive'
            ]);

            /*
            // Add custom validation for NIC uniqueness
            $employeeNic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $personal['nicNumber']));
            $spouseNic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $personal['spouseNic']));

            if ($employeeNic === $spouseNic) {
                $validator->errors()->add("personal.spouseNic", "Spouse NIC cannot be the same as employee NIC.");
            }
                */

            // Add custom validation for NIC uniqueness (SAFE)
            $employeeNic = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $personal['nicNumber'] ?? ''));

            $spouseNicRaw = $personal['spouseNic'] ?? null;
            $spouseNic = $spouseNicRaw
                ? strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $spouseNicRaw))
                : null;

            if ($spouseNic && $employeeNic === $spouseNic) {
                $validator->errors()->add("personal.spouseNic", "Spouse NIC cannot be the same as employee NIC.");
            }

            /*
            // Validate Spouse DOB vs Age
            if (isset($personal['spouseDob']) && isset($personal['spouseAge'])) {
                $spouseDob = Carbon::parse($personal['spouseDob']);
                $calculatedSpouseAge = $spouseDob->diffInYears(Carbon::now());
                $calculatedSpouseAge = (int) floor($calculatedSpouseAge);

                if ($personal['spouseAge'] != $calculatedSpouseAge) {
                    $validator->errors()->add("personal.spouseAge", "Entered Spouse Age does not match Date of Birth.");
                }
            }
       */

            // Validate Spouse DOB vs Age
            if (!empty($personal['spouseDob']) && !empty($personal['spouseAge'])) {
                try {
                    $spouseDob = Carbon::parse($personal['spouseDob']);
                    $calculatedSpouseAge = (int) floor($spouseDob->diffInYears(Carbon::now()));

                    if ((int)$personal['spouseAge'] !== $calculatedSpouseAge) {
                        $validator->errors()->add("personal.spouseAge", "Entered Spouse Age does not match Date of Birth.");
                    }
                } catch (\Exception $e) {
                    // Date  format
                    $validator->errors()->add("personal.spouseDob", "Invalid Date of Birth format.");
                }
            }



            /// Validate Children DOB vs Age for each child
            if (isset($personal['children']) && is_array($personal['children'])) {
                foreach ($personal['children'] as $index => $child) {
                    if (!empty($child['name']) && isset($child['dob']) && isset($child['age'])) {
                        $childDob = Carbon::parse($child['dob']);
                        $calculatedChildAge = $childDob->diffInYears(Carbon::now());
                        $calculatedChildAge = (int) floor($calculatedChildAge);

                        if ($child['age'] != $calculatedChildAge) {
                            $validator->errors()->add("personal.children.$index.age", "Entered Age for child '{$child['name']}' does not match Date of Birth.");
                        }
                    }
                }
            }

            // Add errors to main validator
            foreach ($personalValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("personal.$key", $message);
                }
            }

            foreach ($addressValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("address.$key", $message);
                }
            }

            foreach ($compensationValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("compensation.$key", $message);
                }
            }

            foreach ($organizationValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("organization.$key", $message);
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $profilePicturePath = $employee->profile_photo_path;
            if ($request->hasFile('profile_picture')) {
                // Delete old profile picture if exists
                if ($profilePicturePath && Storage::disk('public')->exists($profilePicturePath)) {
                    Storage::disk('public')->delete($profilePicturePath);
                }

                $profilePicturePath = $request->file('profile_picture')->store('employee/profile_pictures', 'public');
                $personal['profile_picture_path'] = $profilePicturePath;
            }

            // Update spouse record - Safe access
            if ($employee->spouse) {
                $employee->spouse()->update([
                    'type' => $personal['relationshipType'] ?? null,
                    'title' => $personal['spouseTitle'] ?? null,
                    'name' => $personal['spouseName'] ?? null,
                    'nic' => $personal['spouseNic'] ?? null,
                    'age' => $personal['spouseAge'] ?? null,
                    'dob' => $personal['spouseDob'] ?? null,
                ]);
            }

            // Update organization assignment
            if ($employee->organizationAssignment) {
                $companyId = is_numeric($organization['company']) ? $organization['company'] : $employee->organizationAssignment->company_id;
                $designationId = is_numeric($organization['designation']) ? $organization['designation'] : $employee->organizationAssignment->designation_id;
                $deptId = !empty($organization['department']) ? (is_numeric($organization['department']) ? $organization['department'] : $employee->organizationAssignment->department_id) : null;
                $subDeptId = !empty($organization['subDepartment']) ? (is_numeric($organization['subDepartment']) ? $organization['subDepartment'] : $employee->organizationAssignment->sub_department_id) : null;

                $employee->organizationAssignment()->update([
                    'company_id' => $companyId,
                    'department_id' => $deptId,
                    'sub_department_id' => $subDeptId,
                    'designation_id' => $designationId,
                    'current_supervisor' => $organization['currentSupervisor'] ?? null,
                    'date_of_joining' => $organization['dateOfJoined'],
                    'day_off' => empty($organization['dayOff']) ? null : $organization['dayOff'],
                    'confirmation_date' => empty($organization['confirmationDate']) ? null : $organization['confirmationDate'],
                    'probationary_period' => $organization['probationPeriod'],
                    'training_period' => $organization['trainingPeriod'],
                    'contract_period' => $organization['contractPeriod'],
                    'probationary_period_from' => empty($organization['probationFrom']) ? null : $organization['probationFrom'],
                    'probationary_period_to' => empty($organization['probationTo']) ? null : $organization['probationTo'],
                    'training_period_from' => empty($organization['trainingFrom']) ? null : $organization['trainingFrom'],
                    'training_period_to' => empty($organization['trainingTo']) ? null : $organization['trainingTo'],
                    'contract_period_from' => empty($organization['contractFrom']) ? null : $organization['contractFrom'],
                    'contract_period_to' => empty($organization['contractTo']) ? null : $organization['contractTo'],
                    'date_of_resigning' => empty($organization['confirmationDate']) ? null : $organization['confirmationDate'],
                    'is_active' => $organization['currentStatus'],
                ]);
            }

            // Update employee record
            $employee->update([
                'title' => $personal['title'],
                'attendance_employee_no' => $personal['attendanceEmpNo'],
                'epf' => $personal['epfNo'],
                'nic' => $personal['nicNumber'],
                'dob' => $personal['dob'],
                'gender' => strtolower($personal['gender']),
                'religion' => $personal['religion'] ?? null,
                'country_of_birth' => $personal['countryOfBirth'] ?? null,
                'name_with_initials' => $personal['nameWithInitial'],
                'full_name' => $personal['fullName'],
                'display_name' => $personal['displayName'],
                'marital_status' => strtolower($personal['maritalStatus']),
                'is_active' => true,
                'employment_type_id' => $personal['employmentStatus'],
                'profile_photo_path' => $profilePicturePath,
            ]);

            // Handle children records
            if (isset($personal['children']) && is_array($personal['children'])) {
                // First force delete existing children (hard delete to avoid unique constraint issues)
                $employee->children()->forceDelete();

                // Then create new children records
                foreach ($personal['children'] as $child) {
                    if (empty($child['name'])) {
                        continue;
                    }

                    children::create([
                        'employee_id' => $employee->id,
                        'name' => $child['name'],
                        'age' => (int) $child['age'],
                        'dob' => $child['dob'],
                        'nic' => empty($child['nic'] ?? null) ? null : $child['nic'],
                    ]);
                }
            }

            // Handle document uploads if any
            if ($request->hasFile('documents')) {
                // Get the documents metadata from the request
                $documentsMetaJson = $request->input('documents');
                $documentsMeta = is_string($documentsMetaJson) ? json_decode($documentsMetaJson, true) : $documentsMetaJson;
                $documentsMeta = $documentsMeta ?? [];

                foreach ($request->file('documents') as $index => $document) {
                    $path = $document->store('employee/documents', 'public');

                    // Extract the document type (e.g., "nid") from the metadata
                    $documentType = $documentsMeta[$index]['type'] ?? 'unknown';

                    documents::create([
                        'employee_id' => $employee->id,
                        'document_type' => $documentType,
                        'document_path' => $path,
                        'document_name' => $document->getClientOriginalName(),
                    ]);
                }
            }

            // Update contact details
            if ($employee->contactDetail) {
                $employee->contactDetail()->update([
                    'permanent_address' => $address['permanentAddress'],
                    'temporary_address' => $address['temporaryAddress'] ?? null,
                    'email' => $address['email'],
                    'land_line' => $address['landLine'] ?? null,
                    'mobile_line' => $address['mobileLine'] ?? null,
                    'gn_division' => $address['gnDivision'] ?? null,
                    'police_station' => $address['policeStation'] ?? null,
                    'district' => $address['district'] ?? '',
                    'province' => $address['province'] ?? '',
                    'electoral_division' => $address['electoralDivision'] ?? null,
                    'emg_relationship' => $address['emergencyContact']['relationship'],
                    'emg_name' => $address['emergencyContact']['contactName'],
                    'emg_address' => $address['emergencyContact']['contactAddress'],
                    'emg_tel' => $address['emergencyContact']['contactTel'],
                ]);
            }

            // Update compensation record
            if ($employee->compensation) {
                $employee->compensation()->update([
                    'basic_salary' => $compensation['basicSalary'],
                    'increment_value' => $compensation['incrementValue'] ?? null,
                    'increment_effected_date' => empty($compensation['incrementEffectiveFrom']) ? null : $compensation['incrementEffectiveFrom'],
                    'bank_name' => $compensation['bankName'] ?? null,
                    'branch_name' => $compensation['branchName'] ?? null,
                    'bank_code' => $compensation['bankCode'] ?? null,
                    'branch_code' => $compensation['branchCode'] ?? null,
                    'bank_account_no' => $compensation['bankAccountNo'] ?? null,
                    'account_holder_name' => $compensation['accountHolderName'] ?? null,
                    'comments' => $compensation['comments'] ?? null,
                    'secondary_emp' => $compensation['secondaryEmp'],
                    'primary_emp_basic' => $compensation['primaryEmploymentBasic'],
                    'enable_epf_etf' => $compensation['enableEpfEtf'],
                    'ot_active' => $compensation['otActive'],
                    'early_deduction' => $compensation['earlyDeduction'],
                    'increment_active' => $compensation['incrementActive'],
                    'active_nopay' => $compensation['nopayActive'],
                    'ot_morning' => $compensation['morningOt'],
                    'ot_evening' => $compensation['eveningOt'],
                    'ot_morning_rate' => $compensation['ot_morning_rate'] ?? null,
                    'ot_night_rate' => $compensation['ot_night_rate'] ?? null,
                    'br1' => $compensation['budgetaryReliefAllowance2015'],
                    'br2' => $compensation['budgetaryReliefAllowance2016'],
                    'stamp' => $compensation['stamp'],
                    'employee_category' => $organization['employeeCategory'],
                    'monthly_bonus' => $compensation['monthlyBonus'] ?? 0,
                    'sports_fund_percentage' => $compensation['sportsFundPercentage'] ?? null,
                    'staff_fund_amount' => $compensation['staffFundAmount'] ?? 0,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Employee updated successfully',
                'employee_id' => $employee->id
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete the new profile picture if it was uploaded but update failed
            if ($request->hasFile('profile_picture') && $profilePicturePath && Storage::disk('public')->exists($profilePicturePath)) {
                Storage::disk('public')->delete($profilePicturePath);
            }

            Log::error('Employee update error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Employee update failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();

        try {
            $employee = employee::findOrFail($id);

            // Delete profile picture if exists
            if ($employee->profile_photo_path && Storage::disk('public')->exists($employee->profile_photo_path)) {
                Storage::disk('public')->delete($employee->profile_photo_path);
            }

            // Delete documents if any
            // $employee->documents()->each(function ($document) {
            //     if (Storage::disk('public')->exists($document->document_path)) {
            //         Storage::disk('public')->delete($document->document_path);
            //     }
            // });

            // Delete all related records
            $employee->spouse()->delete();
            $employee->children()->delete();
            $employee->contactDetail()->delete();
            // $employee->compensation()->delete();
            $employee->organizationAssignment()->delete();
            // $employee->documents()->delete();

            // Finally delete the employee
            $employee->delete();

            DB::commit();

            return response()->json([
                'message' => 'Employee deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Employee deletion failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function getByNic($identifier)
    {
        // Try matching NIC (case-insensitive) OR attendance_employee_no (exact)
        $employee = employee::with(['organizationAssignment.department'])
            ->where(function ($q) use ($identifier) {
                $q->whereRaw('LOWER(nic) = ?', [strtolower($identifier)])
                    ->orWhere('attendance_employee_no', $identifier);
            })
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        return response()->json([
            'id' => $employee->id,
            'attendance_employee_no' => $employee->attendance_employee_no,
            'full_name' => $employee->full_name,
            'nic' => $employee->nic,
            'department' => $employee->organizationAssignment && $employee->organizationAssignment->department
                ? $employee->organizationAssignment->department->name
                : null,
            // Add other fields as needed
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();



        return response()->json([
            'message' => 'Password changed successfully'
        ], 200);
    }

    public function updateProfilePicture(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'profile_photo' => 'required|image|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $employee = employee::findOrFail($id);
            if ($employee->profile_photo_path && Storage::disk('public')->exists($employee->profile_photo_path)) {
                Storage::disk('public')->delete($employee->profile_photo_path);
            }
            $path = $request->file('profile_photo')->store('employee/profile_pictures', 'public');
            $employee->update(['profile_photo_path' => $path]);
            return response()->json(['message' => 'Profile picture updated', 'profile_photo_path' => $path], 200);
        } catch (\Exception $e) {
            Log::error('Profile picture upload error: ' . $e->getMessage());
            return response()->json(['message' => 'Upload failed', 'error' => $e->getMessage()], 500);
        }
    }
}
