<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\Vendor;
use App\Services\Notification\NotificationService;
use App\Services\Role\RoleService;
use App\Services\User\UserAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class RegistrationService
{
    public function __construct(
        private UserAccountService $userAccountService,
        private RegistrationValidationService $validationService,
        private NotificationService $notificationService,
        private RoleService $roleService
    ) {
    }

    public function registerVendor(array $data): User
    {
        DB::beginTransaction();

        try {
            Log::info('=== STARTING VENDOR REGISTRATION PROCESS ===', $data);

            // Step 1: Validate registration data
            $this->validationService->validateRegistrationData($data);

            // Step 2: Create Vendor business
            $vendor = $this->createVendor($data);

            // Step 3: Create User account (vendor owner)
            $user = $this->createVendorOwner($data, $vendor);

            // Step 4: Assign vendor_owner role
            $this->assignVendorOwnerRole($user);

            // Step 5: Verify role assignment
            $this->verifyRoleAssignment($user, $vendor);

            DB::commit();

            Log::info('=== VENDOR REGISTRATION COMPLETED SUCCESSFULLY ===', [
                'vendor_id' => $vendor->id,
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            // Step 6: Send notifications (outside transaction)
            $this->notificationService->sendVendorRegistrationNotifications($user, $vendor);

            return $user->load(['vendor', 'roles.permissions']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Vendor registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Registration failed: ' . $e->getMessage());
        }
    }

    private function createVendor(array $data): Vendor
    {
        Log::info('Creating Vendor business...');

        $availabilityType = $data['availability_type'] ?? null;
        $availabilityDays = $data['availability_days'] ?? null;

        if ($availabilityType === 'mon_fri') {
            $availabilityDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        } elseif ($availabilityType === 'full_week') {
            $availabilityDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        }

        $categoryInput = $data['service_category'] ?? null;
        $subCategoryInput = $data['service_sub_category'] ?? null;
        $categorySlug = $categoryInput;
        $subCategorySlug = $subCategoryInput;

        if ($categoryInput) {
            $dbCat = \App\Models\ServiceCategory::where('name', $categoryInput)
                ->orWhere('slug', $categoryInput)
                ->first();
            if ($dbCat) {
                $categorySlug = $dbCat->slug;
            } else {
                $categorySlug = \Illuminate\Support\Str::slug($categoryInput);
            }
        }

        if ($subCategoryInput) {
            $dbSub = null;
            if (isset($dbCat)) {
                $dbSub = \App\Models\ServiceSubCategory::where('service_category_id', $dbCat->id)
                    ->where(function($q) use ($subCategoryInput) {
                        $q->where('name', $subCategoryInput)
                          ->orWhere('slug', $subCategoryInput);
                    })->first();
            }
            if (!$dbSub) {
                $dbSub = \App\Models\ServiceSubCategory::where('name', $subCategoryInput)
                    ->orWhere('slug', $subCategoryInput)
                    ->first();
            }

            if ($dbSub) {
                $subCategorySlug = $dbSub->slug;
            } else {
                $subCategorySlug = \Illuminate\Support\Str::slug($subCategoryInput);
            }
        }

        $vendorData = [
            'business_name' => $data['business_name'],
            'website_name' => $data['website_name'],
            'full_name' => $data['full_name'], // Store full name in vendor table
            'email' => $data['email'], // Store email in vendor table
            'mobile_number' => $data['mobile_number'],
            'business_type' => $data['business_type'],
            'service_description' => $data['service_description'] ?? null,
            'service_category' => $categorySlug,
            'service_sub_category' => $subCategorySlug,
            'service_category_custom' => $data['service_category_custom'] ?? null,
            'service_sub_category_custom' => $data['service_sub_category_custom'] ?? null,
            'availability_type' => $availabilityType,
            'availability_days' => $availabilityDays,
            'office_start_time' => $data['office_start_time'] ?? null,
            'office_end_time' => $data['office_end_time'] ?? null,
            'terms_accepted' => $data['terms_accepted'] ?? false,
            'status' => 'active',
        ];

        $vendor = Vendor::create($vendorData);

        Log::info('✅ Vendor business created successfully', [
            'vendor_id' => $vendor->id,
            'business_name' => $vendor->business_name,
            'business_type' => $vendor->business_type
        ]);

        return $vendor;
    }

    private function createVendorOwner(array $data, Vendor $vendor): User
    {
        Log::info('Creating vendor owner user...');

        // Split full_name into first and last name for user table
        $nameParts = explode(' ', $data['full_name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        // Use createVendorUser method instead of createOrUpdateUserAccount
        $userResult = $this->userAccountService->createVendorUser(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'first_name' => $firstName,
                'last_name' => $lastName,
            ],
            $vendor->id,
            null // created_by will be set by BaseModel
        );

        $user = $userResult['user'];

        Log::info('✅ Vendor owner user created successfully', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role_assigned' => $userResult['role_assigned'],
            'vendor_id' => $vendor->id
        ]);

        // Update vendor with created_by reference if columns exist
        try {
            $vendor->update([
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Could not update vendor created_by/updated_by', [
                'vendor_id' => $vendor->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            // Don't throw - this is not critical
        }

        return $user;
    }

    private function assignVendorOwnerRole(User $user): void
    {
        try {
            Log::info('Assigning vendor_owner role to user', ['user_id' => $user->id]);

            $assigned = $this->roleService->assignSystemRole($user, 'vendor_owner');

            if (!$assigned) {
                Log::warning('Failed to assign vendor_owner role, trying company_owner as fallback', ['user_id' => $user->id]);

                // Try fallback to company_owner for backward compatibility
                $assigned = $this->roleService->assignSystemRole($user, 'company_owner');

                if (!$assigned) {
                    throw new \Exception('Failed to assign vendor owner role');
                }
            }

            Log::info('✅ Vendor owner role assigned successfully', ['user_id' => $user->id]);
        } catch (\Exception $e) {
            Log::error('Failed to assign vendor owner role', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e; // Re-throw as this is critical
        }
    }

    private function verifyRoleAssignment(User $user, Vendor $vendor): void
    {
        try {
            $user->load('roles');

            // Verify user has vendor_owner role (or company_owner as fallback)
            if (
                !$this->roleService->userHasRole($user, 'vendor_owner') &&
                !$this->roleService->userHasRole($user, 'company_owner')
            ) {

                Log::warning('User does not have vendor owner role, attempting to assign', [
                    'user_id' => $user->id,
                    'current_roles' => $user->roles->pluck('slug')->toArray()
                ]);

                $assigned = $this->roleService->assignSystemRole($user, 'vendor_owner');

                if (!$assigned) {
                    throw new \Exception('Failed to assign vendor owner role during verification');
                }

                Log::info('Vendor owner role assigned during verification', [
                    'user_id' => $user->id,
                    'vendor_id' => $vendor->id
                ]);
            } else {
                Log::info('Vendor owner role already assigned', [
                    'user_id' => $user->id,
                    'vendor_id' => $vendor->id,
                    'roles' => $user->roles->pluck('slug')->toArray()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to verify role assignment', [
                'user_id' => $user->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage()
            ]);
            // Don't throw - role verification failure is logged but doesn't block registration
        }
    }
}
