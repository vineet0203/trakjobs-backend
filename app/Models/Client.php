<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Client extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'client_type',
        'first_name',
        'last_name',
        'business_name',
        'business_type',
        'industry',
        'business_registration_number',
        'contact_person_name',
        'designation',
        'email',
        'mobile_number',
        'alternate_mobile_number',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'country',
        'zip_code',
        'billing_name',
        'payment_term',
        'preferred_currency',
        'is_tax_applicable',
        'tax_percentage',
        'website_url',
        'logo_path',
        'service_category',
        'service_sub_category',
        'notes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'client_type' => 'string',
        'is_tax_applicable' => 'boolean',
        'tax_percentage' => 'integer',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function availabilitySchedules()
    {
        return $this->hasMany(ClientAvailabilitySchedule::class);
    }

    public function activeAvailabilitySchedule()
    {
        return $this->hasOne(ClientAvailabilitySchedule::class)
            ->where('is_active', true)
            ->latest();
    }

    /**
     * Accessors
     */
    public function getFullNameAttribute(): string
    {
        if ($this->client_type === 'commercial') {
            return $this->business_name ?? 'N/A';
        }
        return trim($this->first_name . ' ' . $this->last_name) ?: 'N/A';
    }

    public function getIsResidentialAttribute(): bool
    {
        return $this->client_type === 'residential';
    }

    public function getIsCommercialAttribute(): bool
    {
        return $this->client_type === 'commercial';
    }

    // Availability Schedule Accessors
    public function getAvailableDaysAttribute(): ?array
    {
        return $this->activeAvailabilitySchedule?->available_days;
    }

    public function getPreferredStartTimeAttribute(): ?string
    {
        return $this->activeAvailabilitySchedule?->preferred_start_time;
    }

    public function getPreferredEndTimeAttribute(): ?string
    {
        return $this->activeAvailabilitySchedule?->preferred_end_time;
    }

    public function getHasLunchBreakAttribute(): ?bool
    {
        return $this->activeAvailabilitySchedule?->has_lunch_break;
    }

    public function getLunchStartAttribute(): ?string
    {
        return $this->activeAvailabilitySchedule?->lunch_start;
    }

    public function getLunchEndAttribute(): ?string
    {
        return $this->activeAvailabilitySchedule?->lunch_end;
    }

    /**
     * Business Logic Methods
     */
    public function isAvailableOnDate(string $date): bool
    {
        $schedule = $this->activeAvailabilitySchedule;
        if (!$schedule) return false;

        $dayOfWeek = strtolower(Carbon::parse($date)->englishDayOfWeek);
        return $schedule->isAvailableOnDay($dayOfWeek);
    }

    public function isAvailableOnDay(string $day): bool
    {
        $schedule = $this->activeAvailabilitySchedule;
        if (!$schedule) return false;

        return $schedule->isAvailableOnDay(strtolower($day));
    }

    public function checkAvailability(string $date, string $startTime, string $endTime): bool
    {
        $schedule = $this->activeAvailabilitySchedule;
        if (!$schedule) return false;

        // Check day of week
        $dayOfWeek = strtolower(Carbon::parse($date)->englishDayOfWeek);
        if (!$schedule->isAvailableOnDay($dayOfWeek)) {
            return false;
        }

        // Check time slot
        return $schedule->isTimeSlotAvailable($startTime, $endTime);
    }

    public function getNextAvailableDates(int $count = 5): array
    {
        $schedule = $this->activeAvailabilitySchedule;
        if (!$schedule) return [];

        return $schedule->getNextAvailableDates($count);
    }

    public function getAvailableTimeSlots(string $date): array
    {
        $schedule = $this->activeAvailabilitySchedule;
        if (!$schedule) return [];

        return $schedule->getAvailableTimeSlots($date);
    }

    public function hasActiveSchedule(): bool
    {
        return $this->activeAvailabilitySchedule !== null;
    }

    /**
     * Scopes
     */
    public function scopeCommercial($query)
    {
        return $query->where('client_type', 'commercial');
    }

    public function scopeResidential($query)
    {
        return $query->where('client_type', 'residential');
    }

    public function scopeWithActiveSchedule($query)
    {
        return $query->whereHas('availabilitySchedules', function ($q) {
            $q->where('is_active', true);
        });
    }

    public function scopeAvailableOnDay($query, string $day)
    {
        $day = strtolower($day);
        return $query->whereHas('availabilitySchedules', function ($q) use ($day) {
            $q->where('is_active', true)
                ->whereJsonContains('available_days', $day);
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}