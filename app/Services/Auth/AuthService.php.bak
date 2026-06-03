<?php

namespace App\Services\Auth;


use App\Http\Resources\Api\V1\User\AuthUserResource;
use App\Models\User;
use App\Models\UserSecurityLog;
use Illuminate\Support\Facades\Log;


class AuthService
{
    public function __construct(
        private PasswordService $passwordService,
        private RegistrationService $registrationService

    ) {}

    /**
     * Login user and return JWT token with account lockout handling
     */
    public function login(array $credentials): array
    {
        // Step 1: Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            // Don't reveal if user exists for security
            throw new \Exception('Invalid credentials');
        }

        // Step 2: Block system users
        if ($user->is_system) {
            throw new \Exception('This account is a system account and cannot be used to sign in.');
        }

        // Step 3: Check if account is already locked
        if ($user->isAccountLocked()) {
            throw new \Exception('Account is temporarily locked. Please try again later or contact administrator.');
        }

        // Step 4: Check if user is active
        if (!$user->is_active) {
            throw new \Exception('Account is deactivated. Please contact administrator.');
        }

        // Step 5: Check if user is active
        if ($user->vendor_id) {
            if ($user->vendor->status !== 'active') {
                throw new \Exception('Vendor account is not active');
            }
        }

        // Step 6: Attempt authentication
        $token = auth()->attempt($credentials);

        if (!$token) {
            // Log failed login attempt
            if (class_exists(UserSecurityLog::class)) {
                UserSecurityLog::logEvent($user, 'login_failed', [
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'failed_attempts' => $user->failed_login_attempts + 1
                ]);
            }

            // Increment failed login attempts
            $user->incrementFailedLoginAttempts();

            // Check if account got locked after this attempt
            if ($user->isAccountLocked()) {
                // Get lockout duration from service
                $lockoutMinutes = $this->passwordService->getSecuritySettings($user)['lockout_duration_minutes'] ?? 15;
                throw new \Exception('Too many failed login attempts. Account has been locked for ' . $lockoutMinutes . ' minutes.');
            }

            // Check remaining attempts
            $maxAttempts = $this->passwordService->getSecuritySettings($user)['max_login_attempts'] ?? 5;
            $remainingAttempts = $maxAttempts - $user->failed_login_attempts;

            if ($remainingAttempts > 0) {
                throw new \Exception('Invalid credentials. ' . $remainingAttempts . ' attempt(s) remaining.');
            } else {
                throw new \Exception('Invalid credentials. Account locked.');
            }
        }

        // Step 7: Login successful - reset failed attempts
        $user->resetFailedLoginAttempts();

        // Step 8: Update last login info
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        // Step 9: Log successful login
        if (class_exists(UserSecurityLog::class)) {
            UserSecurityLog::logEvent($user, 'login_success', [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Register a new user and vendor account
     */
    public function register(array $data): User
    {
        return $this->registrationService->registerVendor($data);
    }

    /**
     * Update user password
     */
    public function updatePassword(User $user, array $data): bool
    {
        try {
            Log::info('Updating password for user', ['user_id' => $user->id]);

            // Check if user should force password change
            if ($user->shouldForcePasswordChange()) {
                Log::warning('User should use force password change endpoint', ['user_id' => $user->id]);
                throw new \Exception('Please use the force password change endpoint');
            }

            // Use PasswordService to update password
            $success = $this->passwordService->updatePassword($user, $data);

            if (!$success) {
                throw new \Exception('Failed to update password');
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update password', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        $user = auth()->user();

        // Log logout event
        if ($user && class_exists(UserSecurityLog::class)) {
            UserSecurityLog::logEvent($user, 'logout', [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
        }

        auth()->logout();
    }

    /**
     * Refresh JWT token
     */
    public function refresh(): array
    {
        $token = auth()->refresh();
        return $this->respondWithToken($token);
    }

    /**
     * Get token response
     */
    private function respondWithToken(string $token): array
    {
        $user = auth()->user()->load([
            'vendor',
            'roles.permissions',
        ]);

        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => new AuthUserResource($user),
        ];
    }


    /**
     * Send password reset link
     */
    public function sendPasswordResetLink(string $email): bool
    {
        try {
            $user = User::where('email', $email)->first();

            if (!$user) {
                Log::warning('Password reset requested for non-existent email', ['email' => $email]);
                return true; // Return true for security
            }
            // Generate reset token
            $token = app('auth.password.broker')->createToken($user);
            $this->passwordService->sendPasswordResetEmail($user, $token);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send password reset link', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Reset password with token
     */

    public function resetPassword(array $data): bool
    {
        try {
            $email = $data['email'] ?? null;
            $token = $data['token'] ?? null;
            $password = $data['password'] ?? null;

            // Find user by email
            $user = User::where('email', $email)->first();

            if (!$user) {
                // Security: Don't reveal if user exists
                // Throw exception instead of returning false
                throw new \Exception('Sorry, something went wrong. please check your input and try again.');
            }

            // Check if user can reset password
            if (!$this->canUserResetPassword($user)) {
                throw new \Exception('Unable to reset password at this time.');
            }

            // Validate token using PasswordService
            if (!$this->passwordService->validatePasswordResetToken($user, $token)) {
                Log::warning('Invalid password reset token', [
                    'user_id' => $user->id,
                    'email' => $email,
                    'ip' => request()->ip()
                ]);

                throw new \Exception('Invalid or expired reset token.');
            }
            // Use PasswordService to reset the password with all validations
            // This will throw exceptions for password validation errors
            $success = $this->passwordService->resetPasswordWithToken($user, $password, $token);

            if ($success) {
                return true;
            }
            // If we get here without an exception, something unexpected happened
            throw new \Exception('Failed to reset password. Please try again.');
        } catch (\Exception $e) {
            Log::error('Failed to reset password', [
                'email' => $data['email'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => request()->ip()
            ]);

            // Re-throw the exception so controller can catch it
            throw $e;
        }
    }

    /**
     * Resend verification email
     */
    public function resendVerification(string $email): bool
    {
        try {
            $user = User::where('email', $email)->first();

            if (!$user) {
                return true; // Don't reveal if user exists
            }

            // Implement resend verification logic

            Log::info('Verification email resend requested', [
                'user_id' => $user->id,
                'email' => $email
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to resend verification email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    /**
     * Check if user is eligible for password reset
     */
    private function canUserResetPassword(User $user): bool
    {
        // Don't allow system users to reset password
        if ($user->is_system) {
            Log::warning('Password reset attempted for system user', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            return false;
        }

        // Check if user is active
        if (!$user->is_active) {
            Log::warning('Password reset attempted for inactive user', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            return false;
        }

        // Check if account is locked using User model method
        if ($user->isAccountLocked()) {
            Log::warning('Password reset attempted for locked account', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            return false;
        }

        // Check rate limiting using UserSecurityLog
        $recentResets = UserSecurityLog::where('user_id', $user->id)
            ->where('event_type', 'password_reset_success')
            ->where('event_time', '>', now()->subDay())
            ->count();

        // Get max reset attempts from security settings
        $securitySettings = $this->passwordService->getSecuritySettings($user);
        $maxResetsPerDay = $securitySettings['max_reset_attempts_per_day'] ?? 3;

        if ($recentResets >= $maxResetsPerDay) {
            Log::warning('Password reset rate limit exceeded', [
                'user_id' => $user->id,
                'email' => $user->email,
                'recent_resets' => $recentResets,
                'max_allowed' => $maxResetsPerDay
            ]);
            return false;
        }

        return true;
    }
}
