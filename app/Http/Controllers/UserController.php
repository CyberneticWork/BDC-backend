<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AclService;
use App\Services\EmployeeUserLinker;
use App\Services\SuperAdminAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::query()
            ->with(['employee:id,attendance_employee_no,full_name,nic'])
            ->get();

        return response()->json($users, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Custom validation for clarity
        $allowedRoles = app(AclService::class)->allowedRoleKeys();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', 'string', Rule::in($allowedRoles)],
            'employee_link' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $actor = $request->user();
        $actorIsAdmin = strtolower((string) ($actor?->role)) === 'admin'
            || app(SuperAdminAuth::class)->isSuperAdmin($actor);
        if ($request->role === 'admin' && !$actorIsAdmin) {
            return response()->json(['message' => 'Only Admin can create Administrator users.'], 403);
        }
        if (app(SuperAdminAuth::class)->identifierMatches((string) $request->email)) {
            return response()->json(['message' => 'That email is reserved for Super Admin.'], 422);
        }

        $linker = app(EmployeeUserLinker::class);
        if (trim((string) $request->input('employee_link')) !== '') {
            if (! $linker->findEmployeeByLink((string) $request->input('employee_link'))) {
                return response()->json([
                    'message' => 'No employee found for that attendance number, EPF or NIC.',
                    'errors' => ['employee_link' => ['No employee found for that attendance number, EPF or NIC.']],
                ], 422);
            }
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => $request->role,
        ]);

        $this->applyEmployeeLink($user, $request->input('employee_link'));

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user->fresh('employee'),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with(['employee:id,attendance_employee_no,full_name,nic'])->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        return response()->json($user, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // Find the user
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Validate request data
        $allowedRoles = app(AclService::class)->allowedRoleKeys();
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|nullable|string|min:8',
            'role' => ['sometimes', 'string', Rule::in($allowedRoles)],
            'employee_link' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update user fields if they exist in the request
        if ($request->has('name')) {
            $user->name = $request->name;
        }

        if ($request->has('email')) {
            $user->email = $request->email;
        }

        // Only update password if it's provided and not null/empty
        if ($request->has('password') && !empty($request->password)) {
            $user->password = Hash::make($request->password);
        }

        if ($request->has('role')) {
            $actor = $request->user();
            $actorIsAdmin = strtolower((string) ($actor?->role)) === 'admin'
                || app(SuperAdminAuth::class)->isSuperAdmin($actor);
            if ($request->role === 'admin' && !$actorIsAdmin) {
                return response()->json(['message' => 'Only Admin can assign the Administrator role.'], 403);
            }
            $user->role = $request->role;
        }

        $user->save();

        $linkError = $this->applyEmployeeLink($user, $request->input('employee_link'));
        if ($linkError) {
            return response()->json([
                'message' => $linkError,
                'errors' => ['employee_link' => [$linkError]],
                'user' => $user->fresh('employee'),
            ], 422);
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->fresh('employee'),
        ], 200);
    }

    private function applyEmployeeLink(User $user, mixed $link): ?string
    {
        $value = trim((string) $link);
        if ($value === '') {
            return null;
        }

        try {
            app(EmployeeUserLinker::class)->linkUserToEmployee($user, $value);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user->delete();
        return response()->json(['message' => 'User deleted successfully'], 204);
    }
}
