<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function __construct(private JwtTokenService $jwt)
    {
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string|min:3|max:190',
            'password' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->invalidCredentials();
        }

        $identifier = trim((string) $request->identifier);
        $password = (string) $request->password;
        $throttleKey = 'hr-login:'.Str::lower($identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
            return response()->json([
                'message' => 'Too many login attempts. Try again later.',
            ], 429);
        }

        $user = $this->findUserByIdentifier($identifier);
        $hash = $user?->getAuthPassword();
        $ok = $this->passwordMatches($password, $hash);

        if (!$user || !$ok) {
            RateLimiter::hit($throttleKey, 15 * 60);

            return $this->invalidCredentials();
        }

        RateLimiter::clear($throttleKey);

        return $this->issueJwtResponse($user);
    }

    private function invalidCredentials()
    {
        return response()->json([
            'message' => 'The provided credentials are incorrect.',
        ], 401);
    }

    private function issueJwtResponse(User $user): JsonResponse
    {
        $token = $this->jwt->issue($user);

        return response()->json([
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'is_first_login' => (bool) $user->is_first_login,
        ], 200);
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        $lower = strtolower($identifier);

        return User::query()
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
    }

    /**
     * Verify a password against bcrypt hashes from PHP ($2y$) or Node ($2b$).
     */
    private function passwordMatches(string $plain, ?string $hashed): bool
    {
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $normalized = $hashed ?: $dummy;
        if (str_starts_with($normalized, '$2b$')) {
            $normalized = '$2y$' . substr($normalized, 4);
        }

        try {
            $ok = Hash::check($plain, $normalized);
        } catch (\Throwable $e) {
            $ok = password_verify($plain, $normalized);
        }

        return $hashed ? $ok : false;
    }

    private function otpMatches(string $plain, ?string $stored): bool
    {
        if ($stored === null || $stored === '' || $plain === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2b$') || str_starts_with($stored, '$2a$')) {
            $normalized = str_starts_with($stored, '$2b$') ? '$2y$'.substr($stored, 4) : $stored;

            return Hash::check($plain, $normalized);
        }

        return hash_equals($stored, $plain);
    }

    public function loginWithOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return $this->invalidCredentials();
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !$this->otpMatches((string) $request->otp, $user->otp)) {
            return $this->invalidCredentials();
        }

        if ($user->otp_expires_at && Carbon::parse($user->otp_expires_at)->isPast()) {
            return $this->invalidCredentials();
        }

        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return $this->issueJwtResponse($user);
    }

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

    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'If that account exists, an OTP has been sent.',
            ], 200);
        }

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
                Log::error('Failed to send OTP email.');
            }
        }

        return response()->json([
            'message' => 'If that account exists, an OTP has been sent.',
        ], 200);
    }

    public function generateOtp($userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return false;
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->otp = Hash::make($otp);
        $user->otp_expires_at = Carbon::now()->addHours(24);
        $user->is_first_login = true;
        $user->save();

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
            Log::error('Failed to send OTP email.');
            return false;
        }
    }

    public function register(Request $request)
    {
        return response()->json(['message' => 'Registration is disabled.'], 403);
    }
}
