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
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Password is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$this->auth->passwordMatches((string) $request->input('password'))) {
            return response()->json(['message' => 'Invalid password.'], 401);
        }

        return response()->json([
            'token' => $this->auth->issueToken(),
            'role' => 'cybernetic_admin',
        ]);
    }

    public function me()
    {
        return response()->json([
            'role' => 'cybernetic_admin',
            'name' => 'Cybernetic Admin',
        ]);
    }
}
