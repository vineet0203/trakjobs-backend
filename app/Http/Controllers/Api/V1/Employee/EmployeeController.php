<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Exceptions\CrossRoleEmailConflictException;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Employees\CreateEmployeeRequest;
use App\Http\Requests\Api\V1\Employees\UpdateEmployeeRequest;
use App\Http\Requests\Api\V1\Employees\GetEmployeesRequest;
use App\Http\Resources\Api\V1\Employee\EmployeeCollection;
use App\Http\Resources\Api\V1\Employee\EmployeeResource;
use App\Services\Employee\EmployeeCreationService;
use App\Services\Employee\EmployeeQueryService;
use App\Services\Employee\EmployeeUpdateService;
use App\Services\Employee\EmployeeDeletionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmployeeController extends BaseController
{
    use ApiResponse;

    private EmployeeCreationService $employeeCreationService;
    private EmployeeQueryService $employeeQueryService;
    private EmployeeUpdateService $employeeUpdateService;
    private EmployeeDeletionService $employeeDeletionService;

    public function __construct(
        EmployeeCreationService $employeeCreationService,
        EmployeeQueryService $employeeQueryService,
        EmployeeUpdateService $employeeUpdateService,
        EmployeeDeletionService $employeeDeletionService
    ) {
        $this->employeeCreationService = $employeeCreationService;
        $this->employeeQueryService = $employeeQueryService;
        $this->employeeUpdateService = $employeeUpdateService;
        $this->employeeDeletionService = $employeeDeletionService;
    }

    /**
     * Add a new employee
     */
    public function addEmployee(CreateEmployeeRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $validatedData = $request->validated();
            unset($validatedData['employee_id']);
            $validatedData['vendor_id'] = $vendorId;

            $employee = $this->employeeCreationService->create($validatedData, auth()->id());

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
            $emailError = null;

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
                $emailError = $mailException->getMessage();
            }

            Log::info('Employee first-time password setup link generated.', [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'email_sent' => $emailSent,
                'mail_error' => $emailError,
            ]);

            return $this->createdResponse(
                new EmployeeResource($employee),
                $emailSent
                    ? 'Employee added successfully. Password setup email sent.'
                    : 'Employee added successfully, but password setup email could not be sent.',
                [
                    'password_setup' => [
                        'email' => $employee->email,
                        'expires_in_minutes' => 60,
                        'email_sent' => $emailSent,
                    ],
                ]
            );
        } catch (CrossRoleEmailConflictException $e) {
            Log::warning('Cross-role email conflict while adding employee', [
                'error' => $e->getMessage(),
                'existing_role' => $e->getExistingRole(),
                'vendor_id' => $vendorId ?? null,
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422,
                null,
                ['existing_role' => $e->getExistingRole()]
            );
        } catch (\Exception $e) {
            Log::error('Failed to add employee', [
                'error' => $e->getMessage(),
                'vendor_id' => $vendorId ?? null,
            ]);

            return $this->errorResponse(
                'Failed to add employee. Please try again.',
                500
            );
        }
    }

    /**
     * Get all employees for a specific vendor with filtering and pagination
     */
    public function getVendorEmployees(GetEmployeesRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== GET VENDOR EMPLOYEES START ===', [
                'vendor_id' => $vendorId,
                'filters' => $request->all(),
                'user_id' => $user->id
            ]);

            $validated = $request->validated();
            $employees = $this->employeeQueryService->getEmployees(
                $vendorId,
                $validated,
                $validated['per_page'] ?? 15
            );

            return $this->successResponse(
                new EmployeeCollection($employees),
                'Employees retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve employees', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve employees. Please try again.',
                500
            );
        }
    }

    /**
     * Get a single employee by ID
     */
    public function getVendorEmployee(int $employeeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== GET VENDOR EMPLOYEE START ===', [
                'vendor_id' => $vendorId,
                'employee_id' => $employeeId,
            ]);

            $employee = $this->employeeQueryService->getEmployee($vendorId, $employeeId);

            if (!$employee) {
                return $this->notFoundResponse('Employee not found.');
            }

            return $this->successResponse(
                new EmployeeResource($employee),
                'Employee retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve employee', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve employee. Please try again.',
                500
            );
        }
    }

    /**
     * Update an employee
     */
    public function modifyEmployee(UpdateEmployeeRequest $request, int $employeeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== MODIFY EMPLOYEE START ===', [
                'vendor_id' => $vendorId,
                'employee_id' => $employeeId,
                'user_id' => $user->id
            ]);

            $employee = $this->employeeQueryService->getEmployee($vendorId, $employeeId);

            if (!$employee) {
                return $this->notFoundResponse('Employee not found.');
            }

            $validatedData = $request->validated();
            $employee = $this->employeeUpdateService->update($employee, $validatedData, auth()->id());

            return $this->successResponse(
                new EmployeeResource($employee),
                'Employee updated successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to update employee', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to update employee. Please try again.',
                500
            );
        }
    }

    /**
     * Delete/soft delete an employee
     */
    public function removeEmployee(int $employeeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== REMOVE EMPLOYEE START ===', [
                'vendor_id' => $vendorId,
                'employee_id' => $employeeId,
                'deleted_by' => auth()->id(),
            ]);

            $employee = $this->employeeQueryService->getEmployee($vendorId, $employeeId);

            if (!$employee) {
                return $this->notFoundResponse('Employee not found.');
            }

            // Check if employee can be deleted
            $canDelete = $this->employeeDeletionService->canDelete($employee);

            if (!$canDelete['can_delete']) {
                return $this->errorResponse(
                    $canDelete['message'],
                    409
                );
            }

            $this->employeeDeletionService->softDelete($employee, auth()->id());

            return $this->successResponse(
                null,
                'Employee deleted successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to delete employee', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to delete employee. Please try again.',
                500
            );
        }
    }

    /**
     * Get organization hierarchy
     */
    public function getHierarchy(): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $hierarchy = $this->employeeQueryService->getOrganizationHierarchy($vendorId);

            return $this->successResponse(
                $hierarchy,
                'Organization hierarchy retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve hierarchy', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve hierarchy. Please try again.',
                500
            );
        }
    }

    /**
     * Get employee statistics
     */
    public function getStatistics(): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $statistics = $this->employeeQueryService->getEmployeeStatistics($vendorId);

            return $this->successResponse(
                $statistics,
                'Employee statistics retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve employee statistics', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve employee statistics. Please try again.',
                500
            );
        }
    }

    /**
     * Get employees by department
     */
    public function getByDepartment(string $department): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $employees = $this->employeeQueryService->getEmployeesByDepartment($vendorId, $department);

            return $this->successResponse(
                EmployeeResource::collection($employees),
                'Employees retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve employees by department', [
                'department' => $department,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve employees. Please try again.',
                500
            );
        }
    }

    /**
     * Get employees by designation
     */
    public function getByDesignation(string $designation): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $employees = $this->employeeQueryService->getEmployeesByDesignation($vendorId, $designation);

            return $this->successResponse(
                EmployeeResource::collection($employees),
                'Employees retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve employees by designation', [
                'designation' => $designation,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve employees. Please try again.',
                500
            );
        }
    }

    /**
     * Get subordinates of a manager
     */
    public function getSubordinates(int $employeeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            // First verify the manager exists and belongs to this vendor
            $manager = $this->employeeQueryService->getEmployee($vendorId, $employeeId);

            if (!$manager) {
                return $this->notFoundResponse('Manager not found.');
            }

            $subordinates = $this->employeeQueryService->getSubordinates($vendorId, $employeeId);

            return $this->successResponse(
                EmployeeResource::collection($subordinates),
                'Subordinates retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve subordinates', [
                'manager_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve subordinates. Please try again.',
                500
            );
        }
    }
}
