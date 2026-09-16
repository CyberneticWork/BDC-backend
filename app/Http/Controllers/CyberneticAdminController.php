<?php

namespace App\Http\Controllers;

use App\Services\CyberneticAdminAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CyberneticAdminController extends Controller
{
    public function __construct(private CyberneticAdminAuth $auth)
    {
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Password is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ip = (string) $request->ip();
        if (!$this->auth->loginAllowed($ip)) {
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429);
        }

        if (!$this->auth->passwordConfigured()) {
            return response()->json([
                'message' => 'Cybernetic Admin is not configured. Set CYBERNETIC_ADMIN_PASSWORD on the server.',
            ], 503);
        }

        if (!$this->auth->passwordMatches((string) $request->input('password'))) {
            $this->auth->hitLogin($ip);

            return response()->json(['message' => 'Invalid password.'], 401);
        }

        $this->auth->clearLogin($ip);

        return response()->json([
            'token' => $this->auth->issueToken(),
            'role' => 'cybernetic_admin',
            'token_type' => 'Bearer',
            'expires_in_hours' => (int) config('cybernetic.token_ttl_hours', 168),
        ]);
    }

    public function me()
    {
        return response()->json([
            'role' => 'cybernetic_admin',
            'name' => 'Cybernetic Control Plane',
            'session' => [
                'ttl_hours' => (int) config('cybernetic.token_ttl_hours', 168),
                'lockout_minutes' => (int) config('cybernetic.login_decay_minutes', 15),
            ],
            'process_catalog' => \App\Services\CompanyProcessSettings::catalog(),
        ]);
    }

    public function processCatalog()
    {
        return response()->json([
            'data' => \App\Services\CompanyProcessSettings::catalog(),
            'attendance_process_options' => [
                \App\Services\CompanyProcessSettings::SPM_STANDARD,
                \App\Services\CompanyProcessSettings::SHIFT_ROSTER,
            ],
        ]);
    }
}
