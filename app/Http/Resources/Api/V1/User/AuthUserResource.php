<?php

namespace App\Http\Resources\Api\V1\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Get primary role FIRST (before loading relations)
        $primaryRole = $this->getPrimaryRole();

        // Load only relationships needed for this user's role
        $this->loadRelationsByRole($primaryRole);

        return [
            // Basic Info
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->getFullNameAttribute(),
            'is_active' => (bool) $this->is_active,
            'status' => $this->status,
            'verification_status' => $this->verification_status,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Vendor association
            'vendor_id' => $this->vendor_id,

            // Role Information (for frontend routing/UI)
            'primary_role' => $primaryRole,
            'role_slugs' => $this->getRoleSlugsAttribute(),

            // Role checks (for conditional rendering)
            'is_platform_admin' => $this->isPlatformAdmin(),
            'is_vendor_owner' => $this->isVendorOwner(),
            'is_employee' => $this->isEmployee(),
            'is_client' => $this->isClient(),

            // Vendor Info (for vendor-scoped requests)
            'vendor' => $this->when($this->vendor_id, function () {
                return $this->vendor ? [
                    'id' => $this->vendor->id,
                    'business_name' => $this->vendor->business_name,
                    'website_name' => $this->vendor->website_name,
                    'business_type' => $this->vendor->business_type,
                    'service_category' => $this->vendor->service_category,
                    'service_sub_category' => $this->vendor->service_sub_category,
                    'service_category_custom' => $this->vendor->service_category_custom,
                    'service_sub_category_custom' => $this->vendor->service_sub_category_custom,
                    'status' => $this->vendor->status,
                ] : null;
            }),

            // Employee Info (only load for employees)
            'employee' => $this->when($this->isEmployee() && $this->relationLoaded('employee'), function () {
                return $this->employee ? [
                    'id' => $this->employee->id,
                    'employee_code' => $this->employee->employee_code,
                    'full_name' => $this->employee->first_name . ' ' . $this->employee->last_name,
                    'first_name' => $this->employee->first_name,
                    'last_name' => $this->employee->last_name,
                    'middle_name' => $this->employee->middle_name,
                    'preferred_name' => $this->employee->preferred_name,
                    'phone' => $this->employee->phone,
                    'personal_email' => $this->employee->personal_email,
                    'status' => $this->employee->status,
                ] : null;
            }, null),

            // Client Info (only load for clients)
            'client' => $this->when($this->isClient() && $this->relationLoaded('client'), function () {
                return $this->client ? [
                    'id' => $this->client->id,
                    'business_name' => $this->client->business_name,
                    'contact_person_name' => $this->client->contact_person_name,
                    'email' => $this->client->email,
                    'mobile_number' => $this->client->mobile_number,
                    'status' => $this->client->status,
                ] : null;
            }, null),

            // Minimal Role Info (for display only)
            'roles' => $this->when($request->has('include_roles'), function () {
                $this->loadMissing('roles');
                return $this->roles->map(fn($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'scope' => $role->scope,
                    'description' => $role->description,
                ]);
            }),

            // Permission slugs ONLY (for frontend capability checks)
            'permissions' => $this->when($request->has('include_permissions'), function () {
                return $this->getAllPermissionSlugs();
            }, []), // Return empty array by default for security

            // Frontend navigation/routing helpers
            'ui_context' => $this->getUIContext(),
        ];
    }

    /**
     * Load only relationships needed for this user's role
     */
    private function loadRelationsByRole(string $primaryRole): void
    {
        $relationsToLoad = ['vendor'];

        // Add role-specific relationships
        switch ($primaryRole) {
            case 'employee':
                $relationsToLoad['employee'] = function ($query) {
                    $query->select([
                        'id',
                        'user_id',
                        'vendor_id',
                        'employee_code',
                        'first_name',
                        'last_name',
                        'middle_name',
                        'preferred_name',
                        'phone',
                        'personal_email',
                        'profile_image',
                        'status',
                        'created_at',
                        'updated_at'
                    ]);
                };
                break;

            case 'client':
                // Only load client if user_id column exists in clients table
                // If not, you need to fix the relationship first
                $relationsToLoad['client'] = function ($query) {
                    $query->select([
                        'id',
                        'user_id',
                        'vendor_id',
                        'business_name',
                        'contact_person_name',
                        'email',
                        'mobile_number',
                        'status',
                        'created_at',
                        'updated_at'
                    ]);
                };
                break;

            case 'vendor_owner':
            case 'platform_admin':
                // Only load vendor, no need for employee/client
                break;
        }

        $this->loadMissing($relationsToLoad);
    }

    /**
     * Get UI context for frontend (what sections user can access)
     */
    private function getUIContext(): array
    {
        $context = [
            'can_access_dashboard' => true, // All authenticated users
            'can_view_own_profile' => $this->canDo('view_own_profile'),
            'can_update_own_profile' => $this->canDo('update_own_profile'),
        ];

        // Platform Admin context
        if ($this->isPlatformAdmin()) {
            $context['platform_admin'] = true;
            $context['can_manage_vendors'] = $this->canDo('view_all_vendors');
            $context['can_manage_platform_settings'] = $this->canDo('manage_platform_settings');
            $context['can_view_platform_reports'] = $this->canDo('view_platform_reports');
            $context['can_manage_system'] = $this->canDo('manage_system_config');
        }

        // Vendor Owner context
        if ($this->isVendorOwner()) {
            $context['vendor_owner'] = true;
            $context['can_manage_vendor_profile'] = $this->canDo('manage_vendor_profile');
            $context['can_manage_employees'] = $this->canDo('add_employees');
            $context['can_manage_clients'] = $this->canDo('add_clients');
            $context['can_manage_jobs'] = $this->canDo('create_jobs');
            $context['can_manage_schedules'] = $this->canDo('create_schedules');
            $context['can_view_vendor_reports'] = $this->canDo('view_financial_reports');
            $context['can_manage_invoices'] = $this->canDo('create_invoices');
            $context['can_manage_vendor_settings'] = $this->canDo('update_vendor_settings');
        }

        // Employee context
        if ($this->isEmployee()) {
            $context['employee'] = true;
            $context['can_view_assigned_jobs'] = $this->canDo('view_assigned_jobs');
            $context['can_update_job_status'] = $this->canDo('update_job_status');
            $context['can_clock_in_out'] = $this->canDo('clock_in_out');
            $context['can_view_own_schedule'] = $this->canDo('view_own_schedule');
            $context['can_submit_timesheet'] = $this->canDo('submit_timesheet');
        }

        // Client context
        if ($this->isClient()) {
            $context['client'] = true;
            $context['can_request_service'] = $this->canDo('request_service');
            $context['can_view_assigned_jobs'] = $this->canDo('view_assigned_jobs');
            $context['can_track_job_progress'] = $this->canDo('track_job_progress');
            $context['can_view_invoices'] = $this->canDo('view_invoices');
            $context['can_pay_invoices'] = $this->canDo('pay_invoices');
            $context['can_communicate_with_vendor'] = $this->canDo('communicate_with_vendor');
            $context['can_view_service_history'] = $this->canDo('view_service_history');
        }

        return $context;
    }
}
