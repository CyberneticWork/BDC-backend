<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }
        
        $token = $user->createToken($request->email)->plainTextToken;

        return response()->json(['token' => $token], 200);
    }

    // OTP Login for first-time employees
    public function loginWithOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)
                    ->where('otp', $request->otp)
                    ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid OTP or email.',
            ], 401);
        }

        // Check if OTP expired
        if ($user->otp_expires_at && Carbon::parse($user->otp_expires_at)->isPast()) {
            return response()->json([
                'message' => 'OTP has expired.',
            ], 401);
        }

        // Clear OTP after successful login
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        $token = $user->createToken($request->email)->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token' => $token,
            'is_first_login' => $user->is_first_login
        ], 200);
    }

    // Change password
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 401);
        }

        $user->password = Hash::make($request->new_password);
        $user->is_first_login = false;
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
        ], 200);
    }

    // Generate and send OTP
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'message' => 'Email not found.',
            ], 404);
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $user->otp = $otp;
        $user->otp_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        // Send email
        try {
            Mail::raw(
                "Your OTP for login:\n\nOTP: {$otp}\n\nThis OTP will expire in 10 minutes.",
                function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Your Login OTP');
                }
            );
            return response()->json([
                'message' => 'OTP sent successfully to your email.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to send OTP email: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send OTP. Please try again.',
            ], 500);
        }
    }

    // Generate and send OTP (for admin creating employee)
    public function generateOtp($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return false;
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $user->otp = $otp;
        $user->otp_expires_at = Carbon::now()->addHours(24);
        $user->is_first_login = true;
        $user->save();

        // Send email
        try {
            Mail::raw(
                "Your login credentials:\n\nEmail: {$user->email}\nOTP: {$otp}\n\nThis OTP will expire in 24 hours.\n\nPlease login and change your password.",
                function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Your Employee Login Credentials');
                }
            );
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send OTP email: ' . $e->getMessage());
            return false;
        }
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken($request->name)->plainTextToken;

        return response()->json([
            'token' => $token,
        ]);
    }
}
