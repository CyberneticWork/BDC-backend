<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\HrJwtToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string|min:3',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = trim($request->identifier);
        $password = $request->password;
        $throttleKey = 'hr-login:'.Str::lower($identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
            return response()->json([
                'message' => 'Too many login attempts. Try again later.',
            ], 429);
        }

        $user = $this->findUserByIdentifier($identifier);

        if (!$user || !$this->passwordMatches($password, $user->getAuthPassword())) {
            RateLimiter::hit($throttleKey, 15 * 60);

            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        RateLimiter::clear($throttleKey);

        return $this->issueJwtResponse($user);
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        $lower = strtolower($identifier);

        $user = User::query()
            ->where(function ($query) use ($lower, $identifier) {
                $query->whereRaw('LOWER(email) = ?', [$lower])
                    ->orWhereRaw('LOWER(nic) = ?', [$lower]);

                if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                    $query->orWhereHas('employee.contactDetail', function ($contactQuery) use ($lower) {
                        $contactQuery->whereRaw('LOWER(email) = ?', [$lower]);
                    });
                }
            })
            ->first();

        return $user;
    }

    /**
     * Verify a password against bcrypt hashes from PHP ($2y$) or Node ($2b$).
     */
    private function passwordMatches(string $plain, ?string $hashed): bool
    {
        if (!$hashed) {
            return false;
        }

        $normalized = $hashed;
        if (str_starts_with($hashed, '$2b$')) {
            $normalized = '$2y$' . substr($hashed, 4);
        }

        try {
            if (Hash::check($plain, $normalized)) {
                return true;
            }
        } catch (\RuntimeException $e) {
            // Fall through to password_verify for other bcrypt prefixes
        }

        return password_verify($plain, $hashed) || password_verify($plain, $normalized);
    }

    private function otpMatches(string $plain, ?string $stored): bool
    {
        if ($stored === null || $stored === '' || $plain === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2b$') || str_starts_with($stored, '$2a$')) {
            return Hash::check($plain, str_starts_with($stored, '$2b$') ? '$2y$'.substr($stored, 4) : $stored);
        }

        return hash_equals($stored, $plain);
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

        $user = User::where('email', $request->email)->first();
        if (!$user || !$this->otpMatches((string) $request->otp, $user->otp)) {
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

        $response = $this->issueJwtResponse($user);
        $payload = $response->getData(true);
        $payload['is_first_login'] = $user->is_first_login;

        return response()->json($payload, 200);
    }

    private function issueJwtResponse(User $user): JsonResponse
    {
        $days = max(1, (int) config('auth.jwt_ttl_days', 30));
        $jti = (string) Str::uuid();
        $expiresAt = now()->addDays($days);
        $created = $user->createToken('jwt:'.$jti, ['*'], $expiresAt);
        $now = time();
        $jwt = HrJwtToken::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'tid' => (int) $created->accessToken->id,
            'jti' => $jti,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt->getTimestamp(),
        ]);

        return response()->json([
            'token' => $jwt,
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => $days * 86400,
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

        if (!$this->passwordMatches($request->current_password, $user->getAuthPassword())) {
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
                'message' => 'If that account exists, an OTP was sent.',
            ], 200);
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $user->otp = Hash::make($otp);
        $user->otp_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        try {
            Mail::raw(
                "Your OTP for login:\n\nOTP: {$otp}\n\nThis OTP will expire in 10 minutes.",
                function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Your Login OTP');
                }
            );
        } catch (\Exception $e) {
            Log::error('Failed to send OTP email: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'If that account exists, an OTP was sent.',
        ], 200);
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
        
        $user->otp = Hash::make($otp);
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
        return response()->json([
            'message' => 'Public registration is disabled. HR users must be created by an administrator.',
        ], 403);
    }
}
