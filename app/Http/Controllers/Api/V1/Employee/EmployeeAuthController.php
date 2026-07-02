<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

use Illuminate\Support\Facades\Mail;

class EmployeeAuthController extends BaseController
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $employee = Employee::where('email', $request->email)->first();

        if (!$employee) {
            return $this->unauthorizedResponse('Invalid email or password.');
        }

        if (empty($employee->password)) {
            return $this->forbiddenResponse('Please set your password first');
        }

        if (!Hash::check($request->password, $employee->password)) {
            return $this->unauthorizedResponse('Invalid email or password.');
        }

        if (!$employee->is_active) {
            return $this->forbiddenResponse('Employee account is inactive.');
        }

        if ($employee->vendor_id) {
            if ($employee->vendor && $employee->vendor->status !== 'active') {
                return $this->forbiddenResponse('Your vendor account has been suspended.');
            }
        }

        $token = JWTAuth::claims([
            'vendor_id' => $employee->vendor_id,
            'scope' => 'employee',
        ])->fromUser($employee);

        $expiresIn = config('jwt.ttl') ? config('jwt.ttl') * 60 : 1440 * 60;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'employee' => [
                'id' => $employee->id,
                'vendor_id' => $employee->vendor_id,
                'name' => $employee->name ?: trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')),
                'email' => $employee->email,
                'verification_status' => $employee->verification_status,
            ],
        ], 'Employee login successful.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $employee = Employee::where('email', $request->email)->first();

        if (!$employee) {
            return $this->notFoundResponse('Employee not found.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(30);

        DB::table('employee_password_resets')->updateOrInsert(
            ['email' => $employee->email],
            [
                'token' => $token,
                'expires_at' => $expiresAt,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $resetUrl = 'https://employee.trakjobs.com/reset-password?token=' . urlencode($token);

        try {
            Mail::send('emails.reset_password', [
                'name' => $employee->name ?: trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) ?: 'Employee',
                'resetUrl' => $resetUrl,
            ], function ($message) use ($employee) {
                $message->to($employee->email)
                    ->subject('Reset your password - ' . config('app.name', 'TrakJobs'));
            });
        } catch (\Throwable $exception) {
            Log::error('Employee reset password email failed to send.', [
                'email' => $employee->email,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->successResponse(['token' => $token], 'Password reset token generated successfully.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $resetRow = DB::table('employee_password_resets')
            ->where('token', $request->token)
            ->first();

        if (!$resetRow) {
            return $this->errorResponse('Invalid reset token.', 400);
        }

        if (now()->greaterThan(Carbon::parse($resetRow->expires_at))) {
            DB::table('employee_password_resets')->where('token', $request->token)->delete();
            return $this->errorResponse('Reset token has expired.', 400);
        }

        $employee = Employee::where('email', $resetRow->email)->first();

        if (!$employee) {
            DB::table('employee_password_resets')->where('token', $request->token)->delete();
            return $this->notFoundResponse('Employee not found for reset token.');
        }

        $employee->password = Hash::make($request->password);
        $employee->save();

        DB::table('employee_password_resets')->where('token', $request->token)->delete();

        return $this->successResponse(null, 'Password reset successful.');
    }

    public function setPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $tokenRow = DB::table('employee_password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$tokenRow) {
            return $this->errorResponse('Invalid or expired setup token.', 400);
        }

        $isExpired = Carbon::parse($tokenRow->created_at)->lt(now()->subMinutes(60));
        if ($isExpired) {
            DB::table('employee_password_resets')->where('email', $request->email)->delete();
            return $this->errorResponse('Setup token has expired.', 400);
        }

        if (!Hash::check($request->token, $tokenRow->token)) {
            return $this->errorResponse('Invalid or expired setup token.', 400);
        }

        $employee = Employee::where('email', $request->email)->first();
        if (!$employee) {
            DB::table('employee_password_resets')->where('email', $request->email)->delete();
            return $this->notFoundResponse('Employee not found.');
        }

        $employee->password = Hash::make($request->password);
        $employee->save();

        DB::table('employee_password_resets')->where('email', $request->email)->delete();

        return $this->successResponse(null, 'Password set successfully. You can now log in.');
    }

    public function me(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('employee');

        return $this->successResponse([
            'id' => $employee['id'],
            'vendor_id' => $employee['vendor_id'],
        ], 'Employee authenticated successfully.');
    }
}
