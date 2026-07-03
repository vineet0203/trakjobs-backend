<?php

namespace App\Models;

use App\Models\Relations\UserRelations;
use App\Services\Auth\PasswordService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Auth;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, UserRelations;

    public $timestamps = true;

    protected $fillable = [
        // Vendor association
        'vendor_id',

        // Identity
        'email',
        'first_name',
        'last_name',

        // Authentication
        'password',
        'email_verified_at',
        'password_changed_at',
        'last_password_reset_at',
        'force_password_change',

        // Account state
        'is_active',
        'status',
        'verification_status',
        'is_system',

        // Deactivation / Reactivation
        'deactivated_at',
        'reactivated_at',
        'deactivation_reason',
        'reactivation_reason',

        // Login security
        'last_login_at',
        'last_login_ip',
        'failed_login_attempts',
        'account_locked_until',
        'security_settings',

        // Audit fields
        'created_by',
        'updated_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        // Dates
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'last_password_reset_at' => 'datetime',
        'account_locked_until' => 'datetime',
        'deactivated_at' => 'datetime',
        'reactivated_at' => 'datetime',

        // Booleans
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'force_password_change' => 'boolean',

        // Integers
        'failed_login_attempts' => 'integer',

        // JSON
        'security_settings' => 'array',
    ];

    protected $appends = [
        'primary_role',
        'role_slugs',
        'permission_slugs',
        'is_platform_admin',
        'is_vendor_owner',
        'is_employee',
        'is_client',
        'full_name',
    ];

    // ========== BOOT METHOD ==========
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->created_by)) {
                $model->created_by = Auth::id() ?? 1;
            }
            if (empty($model->updated_by)) {
                $model->updated_by = Auth::id() ?? 1;
            }
        });

        static::updating(function ($model) {
            $model->updated_by = Auth::id() ?? $model->updated_by;
        });
    }

    // ========== JWT METHODS ==========
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'email' => $this->email,
            'vendor_id' => $this->vendor_id,
            'primary_role' => $this->getPrimaryRole(),
            'roles' => $this->getRoleSlugsAttribute(),
            'permissions' => $this->getAllPermissionSlugs(),
            'full_name' => $this->getFullNameAttribute(),
        ];
    }

    // ========== HELPER METHODS ==========

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function logLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Check if user can perform action using RoleCapabilities
     */
    public function canDo(string $action): bool
    {
        return \App\Helpers\RoleCapabilities::can($action, $this->getPrimaryRole(), $this);
    }

    public function isAccountLocked(): bool
    {
        return $this->account_locked_until && $this->account_locked_until->isFuture();
    }

    /**
     * Get security settings from PasswordService
     */
    public function getSecuritySettings($key = null, $default = null)
    {
        // Use PasswordService to get settings
        $passwordService = app(PasswordService::class);
        $settings = $passwordService->getSecuritySettings($this);

        if ($key) {
            return $settings[$key] ?? $default;
        }

        return $settings;
    }

    public function incrementFailedLoginAttempts(): void
    {
        $this->failed_login_attempts++;
        $maxAttempts = $this->getSecuritySettings('max_login_attempts', 5);

        if ($this->failed_login_attempts >= $maxAttempts) {
            $lockoutMinutes = $this->getSecuritySettings('lockout_duration_minutes', 15);
            $this->account_locked_until = now()->addMinutes($lockoutMinutes);

            // Log the lockout
            UserSecurityLog::logEvent($this, 'account_locked', [
                'reason' => 'too_many_failed_attempts',
                'attempts' => $this->failed_login_attempts,
                'locked_until' => $this->account_locked_until
            ]);
        }

        $this->save();
    }

    public function resetFailedLoginAttempts(): void
    {
        if ($this->failed_login_attempts > 0) {
            $this->failed_login_attempts = 0;
            $this->account_locked_until = null;
            $this->save();
        }
    }

    public function isPasswordExpired(): bool
    {
        $expiryDays = $this->getSecuritySettings('password_expiry_days', 90);

        if (!$this->password_changed_at || $expiryDays <= 0) {
            return false;
        }

        return $this->password_changed_at->addDays($expiryDays)->isPast();
    }

    public function getDaysUntilPasswordExpiry(): int
    {
        if (!$this->password_changed_at) {
            return -1;
        }

        $expiryDays = $this->getSecuritySettings('password_expiry_days', 90);
        $expiryDate = $this->password_changed_at->addDays($expiryDays);

        return now()->diffInDays($expiryDate, false);
    }

    public function shouldForcePasswordChange(): bool
    {
        return $this->force_password_change || $this->isPasswordExpired();
    }

    public function logActivity(string $activity, array $metadata = []): void
    {
        UserSecurityLog::logEvent($this, $activity, $metadata);
    }

    public function getRecentActivity(int $limit = 20)
    {
        return $this->securityLogs()
            ->orderBy('event_time', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Scope query to vendor data isolation
     */
    public function scopeVendorScope($query, $vendorId = null)
    {
        // If no vendorId provided, use user's vendor
        if (!$vendorId) {
            $vendorId = $this->vendor_id;
        }

        // Platform admin sees all users (for platform management)
        if ($this->isPlatformAdmin()) {
            return $query;
        }

        // Vendor-scoped users only see users from their vendor
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Check if user can access client data
     */
    public function canAccessClient($clientId): bool
    {
        if ($this->isPlatformAdmin()) {
            return false; // Platform admin cannot access client data directly
        }

        $targetClient = Client::find($clientId);
        if (!$targetClient) {
            return false;
        }

        // Check vendor access
        if (!$this->canAccessVendor($targetClient->vendor_id)) {
            return false;
        }

        // Vendor owner can access all clients in their vendor
        if ($this->isVendorOwner()) {
            return true;
        }

        // Employee can access clients they're assigned to
        if ($this->isEmployee() && $this->employee) {
            return $this->employee->assignedClients()->where('client_id', $clientId)->exists();
        }

        // Client can access their own record
        if ($this->isClient() && $this->client && $this->client->id == $clientId) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can access employee data
     */
    public function canAccessEmployee($employeeId): bool
    {
        if ($this->isPlatformAdmin()) {
            return false; // Platform admin cannot access employee data directly
        }

        $targetEmployee = Employee::find($employeeId);
        if (!$targetEmployee) {
            return false;
        }

        // Check vendor access first
        if (!$this->canAccessVendor($targetEmployee->vendor_id)) {
            return false;
        }

        // Vendor owner can access all employees in their vendor
        if ($this->isVendorOwner()) {
            return true;
        }

        // Employee can access their own record
        if ($this->isEmployee() && $this->employee && $this->employee->id == $employeeId) {
            return true;
        }

        return false;
    }

    // ========== ATTRIBUTE ACCESSORS ==========

    public function getIsPlatformAdminAttribute(): bool
    {
        return $this->isPlatformAdmin();
    }

    public function getIsVendorOwnerAttribute(): bool
    {
        return $this->isVendorOwner();
    }

    public function getIsEmployeeAttribute(): bool
    {
        return $this->isEmployee();
    }

    public function getIsClientAttribute(): bool
    {
        return $this->isClient();
    }

    public function getRoleSlugsAttribute(): array
    {
        return $this->roles()->pluck('slug')->unique()->values()->toArray();
    }

    public function getAllPermissionSlugs(): array
    {
        return $this->getPermissionSlugsAttribute();
    }
}