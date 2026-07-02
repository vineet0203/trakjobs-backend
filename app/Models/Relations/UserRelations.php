<?php

namespace App\Models\Relations;

use App\Models\Vendor;
use App\Models\Employee;
use App\Models\Client;
use App\Models\PasswordHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UserSecurityLog;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait UserRelations
{
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('assigned_by', 'assigned_at')
            ->withTimestamps();
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function client()
    {
        return $this->hasOne(Client::class);
    }

    /**
     * Get team members if user is a manager (for future use if you add manager role)
     */
    public function teamMembers()
    {
        if (!$this->employee) {
            return collect();
        }

        // For now, return empty collection. You can implement this when you add manager functionality
        return collect();
    }

    /**
     * Get the user who assigned roles to this user
     */
    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Get direct permissions relationship
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')->withTimestamps();
    }

    /**
     * Get all permissions for the user through roles
     */
    public function getAllPermissionsAttribute()
    {
        // If roles are already loaded with permissions, use that
        if ($this->relationLoaded('roles') && $this->roles->first()?->relationLoaded('permissions')) {
            return $this->roles->flatMap(function ($role) {
                return $role->permissions;
            })->unique('id')->values();
        }

        // Otherwise, query the database
        return $this->roles()->with('permissions')->get()
            ->flatMap(function ($role) {
                return $role->permissions;
            })
            ->unique('id')
            ->values();
    }

    /**
     * Get all permission slugs for the user
     */
    public function getPermissionSlugsAttribute()
    {
        return $this->all_permissions->pluck('slug')->unique()->values()->toArray();
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole($role)
    {
        if (is_string($role)) {
            return $this->roles->contains('slug', $role);
        }

        return $this->roles->contains('id', $role->id);
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission($permissionSlug)
    {
        // Platform admins have all platform-level permissions
        if ($this->isPlatformAdmin()) {
            // Check if this is a platform-level permission
            $permission = Permission::where('slug', $permissionSlug)->first();
            if ($permission && $permission->scope === 'platform') {
                return true;
            }
            // Platform admin doesn't automatically have vendor-specific permissions
        }

        // Check through roles
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->exists();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->hasRole('platform_admin');
    }

    /**
     * Get primary role slug
     */
    public function getPrimaryRole(): string
    {
        // Try to get from roles hierarchy first
        if ($this->roles && !$this->roles->isEmpty()) {
            $roleHierarchy = [
                'platform_admin' => 100,
                'vendor_owner' => 90,
                'employee' => 80,
                'client' => 70,
            ];

            $sortedRoles = $this->roles->sortByDesc(function ($role) use ($roleHierarchy) {
                return $roleHierarchy[$role->slug] ?? 0;
            });

            $primaryRole = $sortedRoles->first();
            if ($primaryRole) {
                return $primaryRole->slug;
            }
        }

        // Fallback to existing role check methods
        if ($this->isPlatformAdmin()) return 'platform_admin';
        if ($this->isVendorOwner()) return 'vendor_owner';
        if ($this->isEmployee()) return 'employee';
        if ($this->isClient()) return 'client';

        return 'client'; // Default
    }

    /**
     * Get primary role object
     */
    public function getPrimaryRoleAttribute()
    {
        $roleHierarchy = [
            'platform_admin' => 100,
            'vendor_owner' => 90,
            'employee' => 80,
            'client' => 70,
        ];

        $roles = $this->roles->sortByDesc(function ($role) use ($roleHierarchy) {
            return $roleHierarchy[$role->slug] ?? 0;
        });

        return $roles->first();
    }

    public function isVendorOwner(): bool
    {
        return $this->hasRole('vendor_owner');
    }

    public function isEmployee(): bool
    {
        return $this->hasRole('employee');
    }

    public function isClient(): bool
    {
        return $this->hasRole('client');
    }

    // For Security Logs 

    // Get recent security logs
    public function recentSecurityLogs()
    {
        return $this->hasMany(UserSecurityLog::class)->orderBy('event_time', 'desc')->limit(10);
    }

    // Get password histories
    public function passwordHistories()
    {
        return $this->hasMany(PasswordHistory::class);
    }

    // Get all security logs
    public function securityLogs()
    {
        return $this->hasMany(UserSecurityLog::class);
    }

    /**
     * Get accessible employees based on role
     */
    public function getAccessibleEmployees()
    {
        if ($this->isPlatformAdmin()) {
            return Employee::query()->where('id', 0); // Platform admin sees no employees directly
        }

        if (!$this->vendor_id) {
            return Employee::query()->where('id', 0);
        }

        $query = Employee::where('vendor_id', $this->vendor_id);

        // Vendor owner sees all employees
        if ($this->isVendorOwner()) {
            return $query;
        }

        // Employee sees only themselves
        if ($this->isEmployee() && $this->employee) {
            return $query->where('id', $this->employee->id);
        }

        // Client sees no employees directly
        return $query->where('id', 0);
    }

    /**
     * Get assigned clients for employees
     */
    public function assignedClients()
    {
        if (!$this->employee) {
            return collect();
        }

        return $this->employee->assignedClients();
    }

    /**
     * Check if user can access vendor data
     */
    public function canAccessVendor($vendorId): bool
    {
        // Platform admin can access vendor metadata but not operational data
        if ($this->isPlatformAdmin()) {
            return true;
        }

        // User must belong to the vendor
        if ($this->vendor_id != $vendorId) {
            return false;
        }

        // Vendor owner and employees can access their vendor
        if ($this->isVendorOwner() || $this->isEmployee()) {
            return true;
        }

        // Clients can only access if they have a relationship with this vendor
        if ($this->isClient() && $this->client) {
            return $this->client->vendor_id == $vendorId;
        }

        return false;
    }

    public function verificationProfile()
    {
        return $this->morphOne(\App\Models\VerificationProfile::class, 'authenticatable');
    }
}