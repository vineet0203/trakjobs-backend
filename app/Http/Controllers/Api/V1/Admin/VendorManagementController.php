<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Admin\UpdateVendorRequest;
use App\Models\Vendor;
use App\Models\User;
use App\Models\Employee;
use App\Models\Customer;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Job;
use App\Models\Invoice;
use App\Services\Auth\PasswordService;
use App\Services\Employee\EmployeeCreationService;
use App\Services\Employee\EmployeeUpdateService;
use App\Services\Employee\EmployeeDeletionService;
use App\Services\Customer\CustomerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class VendorManagementController extends BaseController
{
    public function __construct(
        private PasswordService $passwordService,
        private EmployeeCreationService $employeeCreationService,
        private EmployeeUpdateService $employeeUpdateService,
        private EmployeeDeletionService $employeeDeletionService,
        private CustomerAccountService $customerAccountService
    ) {}

    /**
     * GET /api/v1/admin/vendors
     * paginated list, search by name/email/business, filter by status
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->get('search');
        $status = $request->get('status');
        $perPage = $request->get('per_page', 10);

        $query = Vendor::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $vendors = $query->latest()->paginate($perPage);

        // Map count statistics
        $vendors->getCollection()->transform(function ($vendor) {
            $vendor->employee_count = Employee::where('vendor_id', $vendor->id)->count();
            
            $clientEmails = Client::where('vendor_id', $vendor->id)->pluck('email');
            $quoteEmails = Quote::where('vendor_id', $vendor->id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
            $jobEmails = Job::where('vendor_id', $vendor->id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
            $vendor->customer_count = $clientEmails->merge($quoteEmails)->merge($jobEmails)->filter()->unique()->count();

            return $vendor;
        });

        return $this->successResponse($vendors, 'Vendors retrieved successfully');
    }

    /**
     * GET /api/v1/admin/vendors/{id}
     * vendor detail with user info, employee count, customer count
     */
    public function show(int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        // Get owner details
        $owner = User::find($vendor->user_id);
        $vendor->owner = $owner;

        $category = \App\Models\ServiceCategory::where('slug', $vendor->service_category)->first();
        $vendor->service_category_slug = $vendor->service_category;
        $vendor->service_category = $category ? $category->name : $vendor->service_category;

        $subCategory = \App\Models\ServiceSubCategory::where('slug', $vendor->service_sub_category)->first();
        $vendor->service_sub_category_slug = $vendor->service_sub_category;
        $vendor->service_sub_category = $subCategory ? $subCategory->name : $vendor->service_sub_category;

        // Get stats
        $vendor->employee_count = Employee::where('vendor_id', $vendor->id)->count();

        $clientEmails = Client::where('vendor_id', $vendor->id)->pluck('email');
        $quoteEmails = Quote::where('vendor_id', $vendor->id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
        $jobEmails = Job::where('vendor_id', $vendor->id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
        $vendor->customer_count = $clientEmails->merge($quoteEmails)->merge($jobEmails)->filter()->unique()->count();

        // Calculate and embed Dashboard stats
        $vendor->total_jobs = Job::where('vendor_id', $vendor->id)->count();
        $vendor->completed_jobs = Job::where('vendor_id', $vendor->id)->where('status', 'completed')->count();
        $vendor->upcoming_jobs = Job::where('vendor_id', $vendor->id)->whereIn('status', ['pending', 'scheduled'])->count();
        $vendor->total_earnings = (float) Job::where('vendor_id', $vendor->id)->sum('paid_amount');
        $vendor->pending_payments = (float) Job::where('vendor_id', $vendor->id)->sum('balance_due');
        
        $vendor->pending_invoices_count = Invoice::whereHas('items.job', function($q) use ($vendor) {
            $q->where('vendor_id', $vendor->id);
        })->where('status', '!=', 'paid')->count();

        // Job Schedule (This Week / Upcoming)
        $vendor->job_schedule = Job::where('vendor_id', $vendor->id)
            ->whereIn('status', ['pending', 'scheduled'])
            ->with(['assignments.employee', 'customer', 'client'])
            ->latest()
            ->limit(10)
            ->get();

        // Past Jobs (Completed)
        $vendor->past_jobs = Job::where('vendor_id', $vendor->id)
            ->where('status', 'completed')
            ->latest()
            ->with(['assignments.employee', 'customer', 'client'])
            ->limit(10)
            ->get();

        // Earnings Summary
        $vendor->earnings_summary = [
            'total_earnings' => (float) Job::where('vendor_id', $vendor->id)->sum('paid_amount'),
            'paid_amount' => (float) Job::where('vendor_id', $vendor->id)->sum('paid_amount'),
            'pending_amount' => (float) Job::where('vendor_id', $vendor->id)->sum('balance_due'),
            'this_month' => [
                'total_earnings' => (float) Job::where('vendor_id', $vendor->id)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('paid_amount'),
                'paid_amount' => (float) Job::where('vendor_id', $vendor->id)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('paid_amount'),
                'pending_amount' => (float) Job::where('vendor_id', $vendor->id)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('balance_due'),
            ]
        ];

        return $this->successResponse($vendor, 'Vendor details retrieved successfully');
    }

    /**
     * PUT /api/v1/admin/vendors/{id}
     * update vendor info
     */
    public function update(UpdateVendorRequest $request, int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        $validated = $request->validated();
        $vendor->update($validated);

        return $this->successResponse($vendor, 'Vendor details updated successfully');
    }

    /**
     * DELETE /api/v1/admin/vendors/{id}
     * soft delete vendor + deactivate all linked users
     */
    public function destroy(int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        DB::beginTransaction();
        try {
            // Soft delete vendor
            $vendor->delete();

            // Deactivate all linked users
            $userIds = User::where('vendor_id', $vendor->id)
                ->orWhere('id', $vendor->user_id)
                ->pluck('id');

            User::whereIn('id', $userIds)->update([
                'is_active' => false,
                'status' => 'inactive',
                'deactivated_at' => now(),
                'deactivation_reason' => 'Suspended by admin due to vendor deletion'
            ]);

            // Deactivate all linked employees
            \App\Models\Employee::where('vendor_id', $vendor->id)->update([
                'is_active' => false,
                'deleted_at' => now(),
                'deleted_by' => auth()->id()
            ]);

            DB::commit();
            return $this->successResponse(null, 'Vendor, users and employees deactivated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to soft delete vendor', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete vendor: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/v1/admin/vendors/{id}/toggle-status
     * activate/deactivate (cascade to all linked users)
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        $newStatus = $vendor->status === 'active' ? 'inactive' : 'active';

        DB::beginTransaction();
        try {
            $vendor->update(['status' => $newStatus]);

            $userIds = User::where('vendor_id', $vendor->id)
                ->orWhere('id', $vendor->user_id)
                ->pluck('id');

            if ($newStatus === 'inactive') {
                User::whereIn('id', $userIds)->update([
                    'is_active' => false,
                    'status' => 'inactive',
                    'deactivated_at' => now(),
                    'deactivation_reason' => 'Suspended by admin'
                ]);
            } else {
                User::whereIn('id', $userIds)->update([
                    'is_active' => true,
                    'status' => 'active',
                    'reactivated_at' => now(),
                    'failed_login_attempts' => 0,
                    'account_locked_until' => null
                ]);
            }

            DB::commit();
            return $this->successResponse($vendor, 'Vendor status toggled successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle vendor status', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to toggle status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/v1/admin/vendors/{id}/reset-password
     * reset vendor's password manually
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $vendor = Vendor::find($id);

        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        $owner = User::find($vendor->user_id);

        if (!$owner) {
            return $this->errorResponse('Vendor owner user not found', 400);
        }

        $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $newPassword = $request->input('password');

        try {
            // Update password using PasswordService
            $result = $this->passwordService->updatePasswordWithHistory($owner, $newPassword, 'admin_reset');

            if (!$result) {
                return $this->errorResponse('Failed to set new password', 500);
            }

            // Explicitly force password change to false so they login directly
            $owner->force_password_change = false;
            $owner->save();

            // Send email
            Mail::raw("Hello,

An administrator has reset your password for TrakJobs Vendor Portal. Please login and change your password immediately.

If you did not request this, contact support immediately.", function ($message) use ($owner) {
                $message->to($owner->email)->subject('TrakJobs - Admin Password Reset');
            });

            return $this->successResponse(null, 'Password updated successfully and email sent');
        } catch (\Exception $e) {
            Log::error('Failed to reset vendor password', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to reset password: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/admin/vendors/{id}/employees
     * paginated list of vendor's employees
     */
    public function employees(Request $request, int $id): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $employees = Employee::where('vendor_id', $id)->latest()->paginate($perPage);

        return $this->successResponse($employees, 'Employees retrieved successfully');
    }

    /**
     * GET /api/v1/admin/vendors/{id}/customers
     * paginated list of vendor's customers
     */
    public function customers(Request $request, int $id): JsonResponse
    {
        $perPage = $request->get('per_page', 10);

        // Find customer emails
        $clientEmails = Client::where('vendor_id', $id)->pluck('email');
        $quoteEmails = Quote::where('vendor_id', $id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
        $jobEmails = Job::where('vendor_id', $id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
        
        $emails = $clientEmails->merge($quoteEmails)->merge($jobEmails)->filter()->unique();

        $customers = Customer::whereIn('email', $emails)->latest()->paginate($perPage);

        return $this->successResponse($customers, 'Customers retrieved successfully');
    }

    /**
     * PATCH /api/v1/admin/vendors/{id}/employees/{uid}/toggle-status
     * activate/deactivate single employee
     */
    public function toggleEmployeeStatus(int $id, int $uid): JsonResponse
    {
        $employee = Employee::where('vendor_id', $id)->where('id', $uid)->first();

        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        $employee->update([
            'is_active' => !$employee->is_active
        ]);

        return $this->successResponse($employee, 'Employee status updated successfully');
    }

    /**
     * PATCH /api/v1/admin/vendors/{id}/customers/{uid}/toggle-status
     * activate/deactivate single customer
     */
    public function toggleCustomerStatus(int $id, int $uid): JsonResponse
    {
        // Check if customer exists and is linked to the vendor
        $customer = Customer::find($uid);

        if (!$customer) {
            return $this->notFoundResponse('Customer not found');
        }

        // Verify relationship
        $clientEmails = Client::where('vendor_id', $id)->pluck('email');
        $quoteEmails = Quote::where('vendor_id', $id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
        $jobEmails = Job::where('vendor_id', $id)->whereNotNull('customer_id')->with('customer')->get()->pluck('customer.email');
        $emails = $clientEmails->merge($quoteEmails)->merge($jobEmails)->filter()->unique();

        if (!$emails->contains($customer->email)) {
            return $this->forbiddenResponse('Customer is not associated with this vendor');
        }

        $newStatus = $customer->status === 'active' ? 'inactive' : 'active';
        $customer->update(['status' => $newStatus]);

        return $this->successResponse($customer, 'Customer status updated successfully');
    }

    /**
     * POST /api/v1/admin/vendors/{id}/employees
     * add new employee for vendor
     */
    public function addEmployee(Request $request, int $id): JsonResponse
    {
        $vendor = Vendor::find($id);
        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        $request->validate([
            'first_name' => 'required|string|max:191',
            'last_name' => 'nullable|string|max:191',
            'email' => 'required|email|max:191',
            'mobile_number' => 'required|string|max:20',
            'designation' => 'required|string|max:191',
            'department' => 'required|string|max:191',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            // Check cross-role email conflict (Customer)
            if (DB::table('customers')->where('email', $request->input('email'))->exists()) {
                return $this->errorResponse('This email is already registered as a Customer.', 422);
            }

            // Check duplicate employee email for this vendor
            if (Employee::where('vendor_id', $id)->where('email', $request->input('email'))->exists()) {
                return $this->errorResponse('An employee with this email already exists for this vendor.', 422);
            }

            $data = $request->all();
            $data['vendor_id'] = $id;
            $data['is_active'] = $request->boolean('is_active', true);

            $employee = $this->employeeCreationService->create($data, auth()->id());

            // Generate password setup token
            $plainToken = Str::random(60);
            DB::table('vendor_password_resets')->updateOrInsert(
                ['email' => $employee->email],
                [
                    'token' => Hash::make($plainToken),
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
            Log::error('Admin failed to add employee', ['vendor_id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to add employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/admin/vendors/{id}/employees/{uid}
     * update employee details
     */
    public function updateEmployee(Request $request, int $id, int $uid): JsonResponse
    {
        $employee = Employee::where('vendor_id', $id)->where('id', $uid)->first();
        if (!$employee) {
            return $this->notFoundResponse('Employee not found');
        }

        $request->validate([
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
            $emailExists = Employee::where('vendor_id', $id)
                ->where('email', $request->input('email'))
                ->where('id', '!=', $uid)
                ->exists();

            if ($emailExists) {
                return $this->errorResponse('Another employee with this email already exists.', 422);
            }

            $data = $request->all();
            $data['is_active'] = $request->boolean('is_active', $employee->is_active);

            $updatedEmployee = $this->employeeUpdateService->update($employee, $data, auth()->id());

            return $this->successResponse($updatedEmployee, 'Employee updated successfully');
        } catch (\Exception $e) {
            Log::error('Admin failed to update employee', ['employee_id' => $uid, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/admin/vendors/{id}/employees/{uid}
     * delete employee
     */
    public function deleteEmployee(int $id, int $uid): JsonResponse
    {
        $employee = Employee::where('vendor_id', $id)->where('id', $uid)->first();
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
            Log::error('Admin failed to delete employee', ['employee_id' => $uid, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/admin/vendors/{id}/customers
     * add new customer and link to vendor
     */
    public function addCustomer(Request $request, int $id): JsonResponse
    {
        $vendor = Vendor::find($id);
        if (!$vendor) {
            return $this->notFoundResponse('Vendor not found');
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'email' => 'required|email|max:191',
            'phone' => 'required|string|max:20',
            'status' => 'nullable|in:active,inactive',
        ]);

        try {
            // Check cross-role email conflict (Employee)
            if (DB::table('employees')->where('email', $request->input('email'))->exists()) {
                return $this->errorResponse('This email is already registered as an Employee.', 422);
            }

            // Check if customer already exists
            $customer = Customer::where('email', $request->input('email'))->first();
            if (!$customer) {
                // Create customer using CustomerAccountService
                $result = $this->customerAccountService->createCustomer([
                    'name' => $request->input('name'),
                    'email' => $request->input('email'),
                    'phone' => $request->input('phone'),
                    'status' => $request->input('status', 'active'),
                ]);
                $customer = $result['customer'];
                $emailSent = $result['email_sent'];
            } else {
                $emailSent = false;
            }

            // Create/find Client link to associate customer with this vendor
            $client = Client::withTrashed()
                ->where('vendor_id', $id)
                ->where('email', $request->input('email'))
                ->first();

            if ($client) {
                if ($client->trashed()) {
                    $client->restore();
                }
                $client->update([
                    'first_name' => $request->input('name'),
                    'mobile_number' => $request->input('phone'),
                    'status' => $request->input('status', 'active'),
                ]);
            } else {
                Client::create([
                    'vendor_id' => $id,
                    'first_name' => $request->input('name'),
                    'email' => $request->input('email'),
                    'mobile_number' => $request->input('phone'),
                    'status' => $request->input('status', 'active'),
                    'client_type' => 'residential',
                ]);
            }

            return $this->successResponse($customer, $emailSent 
                ? 'Customer created successfully and linked to vendor. Setup email sent.' 
                : 'Customer linked to vendor successfully.');
        } catch (\Exception $e) {
            Log::error('Admin failed to add customer', ['vendor_id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to add customer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/admin/vendors/{id}/customers/{uid}
     * update customer details
     */
    public function updateCustomer(Request $request, int $id, int $uid): JsonResponse
    {
        $customer = Customer::find($uid);
        if (!$customer) {
            return $this->notFoundResponse('Customer not found');
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'email' => 'required|email|max:191',
            'phone' => 'required|string|max:20',
            'status' => 'nullable|in:active,inactive',
        ]);

        try {
            // Check unique email excluding current customer
            $emailExists = Customer::where('email', $request->input('email'))
                ->where('id', '!=', $uid)
                ->exists();

            if ($emailExists) {
                return $this->errorResponse('Another customer with this email already exists.', 422);
            }

            // Check if Client link exists for this vendor and customer
            $client = Client::where('vendor_id', $id)
                ->where('email', $customer->email)
                ->first();

            if (!$client) {
                return $this->errorResponse('Customer is not associated with this vendor', 400);
            }

            // Update Customer
            $customer->update([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'status' => $request->input('status', $customer->status),
            ]);

            // Update Client
            $client->update([
                'first_name' => $request->input('name'),
                'email' => $request->input('email'),
                'mobile_number' => $request->input('phone'),
                'status' => $request->input('status', 'active'),
            ]);

            return $this->successResponse($customer, 'Customer updated successfully');
        } catch (\Exception $e) {
            Log::error('Admin failed to update customer', ['customer_id' => $uid, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update customer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/admin/vendors/{id}/customers/{uid}
     * disassociate customer from vendor
     */
    public function deleteCustomer(int $id, int $uid): JsonResponse
    {
        $customer = Customer::find($uid);
        if (!$customer) {
            return $this->notFoundResponse('Customer not found');
        }

        try {
            $client = Client::where('vendor_id', $id)
                ->where('email', $customer->email)
                ->first();

            if ($client) {
                $client->delete();
            }

            return $this->successResponse(null, 'Customer disassociated from vendor successfully');
        } catch (\Exception $e) {
            Log::error('Admin failed to delete customer', ['customer_id' => $uid, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete customer: ' . $e->getMessage(), 500);
        }
    }
}
