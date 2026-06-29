<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Employee;
use App\Models\Vendor;
use App\Models\User;
use App\Models\Schedule;
use App\Services\Employee\EmployeeCreationService;
use App\Services\Employee\EmployeeUpdateService;
use App\Services\Employee\EmployeeDeletionService;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmployeeManagementController extends BaseController
{
    public function __construct(
        private PasswordService $passwordService,
        private EmployeeCreationService $employeeCreationService,
        private EmployeeUpdateService $employeeUpdateService,
        private EmployeeDeletionService $employeeDeletionService
    ) {}

    /**
     * GET /api/v1/admin/employees
     * Paginated list of all employees with optional filters/search
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->get('search');
        $status = $request->get('status');
        $vendorId = $request->get('vendor_id');
        $perPage = $request->get('per_page', 10);

        $query = Employee::query()->with('vendor');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('business_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status !== null && $status !== 'all') {
            $isActive = $status === 'active';
            $query->where('is_active', $isActive);
        }

        if ($vendorId && $vendorId !== 'all') {
            $query->where('vendor_id', $vendorId);
        }

        $employees = $query->orderBy('first_name', 'asc')->orderBy('last_name', 'asc')->paginate($perPage);

        return $this->successResponse($employees, 'Employees retrieved successfully');
    }

    /**
     * GET /api/v1/admin/employees/{id}
     * Retrieve employee details
     */
    public function show(int $id): JsonResponse
    {
        $employee = Employee::with('vendor')->find($id);

        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        return $this->successResponse($employee, 'Employee details retrieved successfully');
    }

    /**
     * POST /api/v1/admin/employees
     * Add new employee globally
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'first_name' => 'required|string|max:191',
            'last_name' => 'nullable|string|max:191',
            'email' => 'required|email|max:191',
            'mobile_number' => 'required|string|max:20',
            'designation' => 'required|string|max:191',
            'department' => 'required|string|max:191',
            'is_active' => 'nullable|boolean',
        ]);

        $vendorId = $request->input('vendor_id');

        try {
            // Check cross-role email conflict (Vendor)
            if (DB::table('users')->where('email', $request->input('email'))->exists()) {
                return $this->errorResponse('This email is already registered as a Vendor.', 422);
            }

            // Check cross-role email conflict (Customer)
            if (DB::table('customers')->where('email', $request->input('email'))->exists()) {
                return $this->errorResponse('This email is already registered as a Customer.', 422);
            }

            // Check duplicate employee email globally or for this vendor (globally is safer since they are users)
            if (Employee::where('email', $request->input('email'))->exists()) {
                return $this->errorResponse('An employee with this email already exists.', 422);
            }

            $data = $request->all();
            $data['is_active'] = $request->boolean('is_active', true);

            $employee = $this->employeeCreationService->create($data, auth()->id());

            // Generate password setup token
            $plainToken = Str::random(60);
            DB::table('employee_password_resets')->updateOrInsert(
                ['email' => $employee->email],
                [
                    'token' => Hash::make($plainToken),
                    'expires_at' => now()->addHours(48),
                    'created_at' => now(),
                ]
            );

            $employeeAppUrl = rtrim(config('app.employee_frontend_url', 'https://employee.trakjobs.com'), '/');
            $setupLink = $employeeAppUrl . '/set-password?email=' . urlencode($employee->email) . '&token=' . urlencode($plainToken);

            $emailSent = true;
            try {
                Mail::send('emails.reset_password', [
                    'name' => trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) ?: ($employee->name ?? 'Employee'),
                    'resetUrl' => $setupLink,
                ], function ($message) use ($employee) {
                    $message->to($employee->email)
                        ->subject('Set your password - ' . config('app.name', 'TrakJobs'));
                });
            } catch (\Throwable $mailException) {
                $emailSent = false;
            }

            return $this->successResponse($employee, $emailSent 
                ? 'Employee added successfully. Password setup email sent.' 
                : 'Employee added successfully, but password setup email could not be sent.');
        } catch (\Exception $e) {
            Log::error('Admin failed to globally add employee', ['vendor_id' => $vendorId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to add employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/admin/employees/{id}
     * Update employee details globally
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'first_name' => 'required|string|max:191',
            'last_name' => 'nullable|string|max:191',
            'email' => 'required|email|max:191',
            'mobile_number' => 'required|string|max:20',
            'designation' => 'required|string|max:191',
            'department' => 'required|string|max:191',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            // Check duplicate email (excluding current employee)
            $emailExists = Employee::where('email', $request->input('email'))
                ->where('id', '!=', $id)
                ->exists();

            if ($emailExists) {
                return $this->errorResponse('Another employee with this email already exists.', 422);
            }

            $data = $request->all();
            $data['is_active'] = $request->boolean('is_active', $employee->is_active);

            $updatedEmployee = $this->employeeUpdateService->update($employee, $data, auth()->id());

            return $this->successResponse($updatedEmployee, 'Employee updated successfully');
        } catch (\Exception $e) {
            Log::error('Admin failed to globally update employee', ['employee_id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/admin/employees/{id}
     * Soft delete employee globally
     */
    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        try {
            $canDelete = $this->employeeDeletionService->canDelete($employee);
            if (!$canDelete['can_delete']) {
                return $this->errorResponse($canDelete['message'], 409);
            }

            $this->employeeDeletionService->softDelete($employee, auth()->id());

            return $this->successResponse(null, 'Employee deleted successfully');
        } catch (\Exception $e) {
            Log::error('Admin failed to globally delete employee', ['employee_id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/v1/admin/employees/{id}/toggle-status
     * Activate/deactivate employee globally
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        $employee->update([
            'is_active' => !$employee->is_active
        ]);

        return $this->successResponse($employee, 'Employee status updated successfully');
    }

    /**
     * PATCH /api/v1/admin/employees/{id}/reset-password
     * Reset employee password manually
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $newPassword = $request->input('password');

        try {
            $employee->update([
                'password' => Hash::make($newPassword)
            ]);

            // Try to find if there is an associated user (or send directly to employee email)
            Mail::raw("Hello,

An administrator has reset your password for TrakJobs Employee Portal. Please login and change your password immediately.

If you did not request this, contact support immediately.", function ($message) use ($employee) {
                $message->to($employee->email)->subject('TrakJobs - Admin Password Reset');
            });

            return $this->successResponse(null, 'Employee password updated successfully and email sent');
        } catch (\Exception $e) {
            Log::error('Failed to reset employee password', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to reset password: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/admin/employees/{id}/schedules
     * Fetch employee past and upcoming schedules/jobs
     */
    public function schedules(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        // Fetch upcoming schedules (start_datetime >= now)
        $upcoming = Schedule::query()
            ->where('employee_id', $id)
            ->where('start_datetime', '>=', now())
            ->with(['job:id,title,work_type,status', 'vendor:id,business_name'])
            ->orderBy('start_datetime')
            ->get();

        // Fetch past schedules (start_datetime < now)
        $past = Schedule::query()
            ->where('employee_id', $id)
            ->where('start_datetime', '<', now())
            ->with(['job:id,title,work_type,status', 'vendor:id,business_name'])
            ->orderByDesc('start_datetime')
            ->get();

        return $this->successResponse([
            'upcoming' => $upcoming,
            'past' => $past,
        ], 'Employee schedules retrieved successfully');
    }
}
