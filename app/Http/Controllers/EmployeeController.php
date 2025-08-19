<?php

namespace App\Http\Controllers;

use App\Models\spouse;
use App\Models\children;
use App\Models\employee;
use App\Models\documents;
use App\Models\compensation;
use Illuminate\Http\Request;
use App\Models\contact_detail;
use Illuminate\Support\Facades\DB;
use App\Models\organization_assignment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
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
            'rosters',
        ])->get();
        return response()->json($employees, 200);
    }

    public function getEmployeesForTable(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search', '');

        $query = Employee::with([
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

    public function search(Request $request)
    {
        $search = $request->input('search', '');

        $query = Employee::query()
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




    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // return response()->json([
        //     'data' => $request->all(),
        // ], 410);

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
                'relationshipType' => 'required|string|max:20',
                'spouseTitle' => 'required|string|max:20',
                'spouseName' => 'required|string|max:100',
                'spouseAge' => 'required|numeric|min:18|max:100',
                'spouseDob' => 'required|date',
                'spouseNic' => [
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
                'emergencyContact.contactAddress' => 'required|string|max:255',
                'emergencyContact.contactTel' => 'required|string|max:20',
            ]);

            $compensationValidator = Validator::make($compensation, [
                'basicSalary' => 'required|numeric',
                'incrementValue' => 'nullable|numeric',
                'incrementEffectiveFrom' => 'nullable|date',
                'bankName' => 'required|string|max:100',
                'branchName' => 'required|string|max:100',
                'bankCode' => 'required|string|max:50',
                'branchCode' => 'required|string|max:50',
                'bankAccountNo' => 'required|string|max:50',
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
                'company' => 'required|string',
                'department' => 'nullable|string',
                'subDepartment' => 'nullable|string',
                'currentSupervisor' => 'nullable|string|max:100',
                'dateOfJoined' => 'required|date',
                'designation' => 'required|string|max:100',
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
                'dayOff' => 'nullable|string'
            ]);

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
            $profilePicturePath = null;
            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')->store('employee/profile_pictures', 'public');
                $personal['profile_picture_path'] = $profilePicturePath;
            }

            // Create spouse record
            $spouse = spouse::create([
                'type' => $personal['relationshipType'],
                'title' => $personal['spouseTitle'],
                'name' => $personal['spouseName'],
                'nic' => $personal['spouseNic'],
                'age' => $personal['spouseAge'],
                'dob' => $personal['spouseDob'],
            ]);

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
                'spouse_id' => $spouse->id,
                'profile_photo_path' => $profilePicturePath,
            ]);

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
                $documentsMeta = $request->input('documents');

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
            ]);

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

            return response()->json([
                'message' => 'Employee creation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
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
            'organizationAssignment.company',
            'organizationAssignment.department',
            'organizationAssignment.subDepartment',
            'organizationAssignment.designation',
            'rosters',
        ])->findOrFail($id);
        return response()->json($employee, 200);
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
    public function update(Request $request, $id)
    {
        return response()->json($request->all(), 200);

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

        // Get the existing employee
        $employee = Employee::findOrFail($id);

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
                'relationshipType' => 'required|string|max:20',
                'spouseTitle' => 'required|string|max:20',
                'spouseName' => 'required|string|max:100',
                'spouseAge' => 'required|numeric|min:18|max:100',
                'spouseDob' => 'required|date',
                'spouseNic' => [
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
                'emergencyContact.contactAddress' => 'required|string|max:255',
                'emergencyContact.contactTel' => 'required|string|max:20',
            ]);

            // Validate compensation data
            $compensationValidator = Validator::make($compensation, [
                'basicSalary' => 'required|numeric',
                'incrementValue' => 'nullable|numeric',
                'incrementEffectiveFrom' => 'nullable|date',
                'bankName' => 'required|string|max:100',
                'branchName' => 'required|string|max:100',
                'bankCode' => 'required|string|max:50',
                'branchCode' => 'required|string|max:50',
                'bankAccountNo' => 'required|string|max:50',
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
            ]);

            // Validate organization data
            $organizationValidator = Validator::make($organization, [
                'company' => 'required|string',
                'department' => 'nullable|string',
                'subDepartment' => 'nullable|string',
                'currentSupervisor' => 'nullable|string|max:100',
                'dateOfJoined' => 'required|date',
                'designation' => 'required|string|max:100',
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
                'dayOff' => 'nullable|string'
            ]);

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
            // Handle profile picture update
            $profilePicturePath = $employee->profile_photo_path;
            if ($request->hasFile('profile_picture')) {
                // Delete old profile picture if exists
                if ($profilePicturePath && Storage::disk('public')->exists($profilePicturePath)) {
                    Storage::disk('public')->delete($profilePicturePath);
                }
                $profilePicturePath = $request->file('profile_picture')->store('employee/profile_pictures', 'public');
            }

            // Update spouse record
            $employee->spouse()->update([
                'type' => $personal['relationshipType'],
                'title' => $personal['spouseTitle'],
                'name' => $personal['spouseName'],
                'nic' => $personal['spouseNic'],
                'age' => $personal['spouseAge'],
                'dob' => $personal['spouseDob'],
            ]);

            // Update organization assignment
            $employee->organizationAssignment()->update([
                'company_id' => $organization['company'],
                'department_id' => $organization['department'] ?? null,
                'sub_department_id' => $organization['subDepartment'] ?? null,
                'designation_id' => $organization['designation'],
                'current_supervisor' => $organization['currentSupervisor'] ?? null,
                'date_of_joining' => $organization['dateOfJoined'],
                'day_off' => $organization['dayOff'],
                'confirmation_date' => $organization['confirmationDate'] ?? null,
                'probationary_period' => $organization['probationPeriod'],
                'training_period' => $organization['trainingPeriod'],
                'contract_period' => $organization['contractPeriod'],
                'probationary_period_from' => $organization['probationFrom'] ?? null,
                'probationary_period_to' => $organization['probationTo'] ?? null,
                'training_period_from' => $organization['trainingFrom'] ?? null,
                'training_period_to' => $organization['trainingTo'] ?? null,
                'contract_period_from' => $organization['contractFrom'] ?? null,
                'contract_period_to' => $organization['contractTo'] ?? null,
                'date_of_resigning' => $organization['resignationDate'] ?? null,
                'resignation_approved' => $organization['resignationApproved'],
                'is_active' => $organization['currentStatus'],
            ]);

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
                'employment_type_id' => $personal['employmentStatus'],
                'profile_photo_path' => $profilePicturePath,
            ]);

            // Handle children updates
            $employee->children()->delete(); // Delete existing children
            if (isset($personal['children']) && is_array($personal['children'])) {
                foreach ($personal['children'] as $child) {
                    if (!empty($child['name'])) {
                        $employee->children()->create([
                            'name' => $child['name'],
                            'age' => (int) $child['age'],
                            'dob' => $child['dob'],
                            'nic' => $child['nic'] ?? null,
                        ]);
                    }
                }
            }

            // Update contact details
            $employee->contactDetail()->update([
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

            // Update compensation record
            $employee->compensation()->update([
                'basic_salary' => $compensation['basicSalary'],
                'increment_value' => $compensation['incrementValue'] ?? null,
                'increment_effected_date' => $compensation['incrementEffectiveFrom'] ?? null,
                'bank_name' => $compensation['bankName'],
                'branch_name' => $compensation['branchName'],
                'bank_code' => $compensation['bankCode'],
                'branch_code' => $compensation['branchCode'],
                'bank_account_no' => $compensation['bankAccountNo'],
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
            ]);

            // Handle document uploads
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $index => $document) {
                    $path = $document->store('employee/documents', 'public');
                    $employee->documents()->create([
                        'document_type' => $request->input('documents')[$index]['type'] ?? 'unknown',
                        'document_path' => $path,
                        'document_name' => $document->getClientOriginalName(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Employee updated successfully',
                'employee_id' => $employee->id
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
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
            $employee = Employee::findOrFail($id);

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


    public function getByNic($nic)
    {
        $employee = employee::with(['organizationAssignment.department'])
            ->whereRaw('LOWER(nic) = ?', [strtolower($nic)])
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        return response()->json([
            'id' => $employee->id,
            'attendance_employee_no' => $employee->attendance_employee_no,
            'full_name' => $employee->full_name,
            'department' => $employee->organizationAssignment && $employee->organizationAssignment->department
                ? $employee->organizationAssignment->department->name
                : null,
            // Add other fields as needed
        ]);
    }
}
