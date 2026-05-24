<?php

namespace App\Http\Resources\Api\V1\Client;

use App\Services\File\SignedUrlService;
use App\Traits\HasSignedUrl;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    use HasSignedUrl;

    public function toArray($request)
    {
        // Get the active schedule using the relationship
        $activeSchedule = $this->getActiveSchedule();

        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            // Support both commercial + residential clients
            'client_type' => $this->client_type ?? 'commercial',
            'first_name' => $this->first_name ?? null,
            'last_name' => $this->last_name ?? null,
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'industry' => $this->industry,
            'business_registration_number' => $this->business_registration_number,
            'contact_person_name' => $this->contact_person_name,
            'designation' => $this->designation,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'alternate_mobile_number' => $this->alternate_mobile_number,
            'address' => [
                'address_line_1' => $this->address_line_1,
                'address_line_2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'country' => $this->country,
                'zip_code' => $this->zip_code,
            ],
            'payment' => [
                'payment_term' => $this->payment_term,
                'preferred_currency' => $this->preferred_currency,
                'billing_name' => $this->billing_name,
            ],
            'tax' => [
                'is_tax_applicable' => (bool) $this->is_tax_applicable,
                'tax_percentage' => $this->tax_percentage,
            ],
            'website_url' => $this->website_url,
            'logo' => $this->getSignedUrlData($this->logo_path),
            'service_category' => $this->service_category,
            'service_sub_category' => $this->service_sub_category,
            'notes' => $this->notes,
            //'status' => $this->status,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Availability scheduling
            'availability_schedule' => $activeSchedule ? new ClientAvailabilityResource($activeSchedule) : null,
        ];
    }

    /**
     * Helper method to get active schedule
     */
    private function getActiveSchedule()
    {
        // First try to use the relationship if it's loaded
        if ($this->relationLoaded('availabilitySchedules')) {
            return $this->availabilitySchedules
                ->where('is_active', true)
                ->where(function ($schedule) {
                    return !$schedule->schedule_end_date ||
                        $schedule->schedule_end_date >= now()->toDateString();
                })
                ->sortByDesc('created_at')
                ->first();
        }

        // If relationship is not loaded, use the activeAvailabilitySchedule relationship
        return $this->activeAvailabilitySchedule;
    }
}
