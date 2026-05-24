<?php
// app/Http/Resources/Api/V1/Job/JobClientResource.php

namespace App\Http\Resources\Api\V1\Job;

use Illuminate\Http\Resources\Json\JsonResource;

class JobClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'client_type' => $this->client_type,

            // Unified fields that work for both types
            'name' => $this->getClientName(),
            'contact_name' => $this->getContactName(),
            'contact_email' => $this->email,
            'contact_phone' => $this->mobile_number,
            // Metadata
            'category' => $this->service_category,
            'sub_category' => $this->service_sub_category,
        ];
    }

    /**
     * Get client name (business name for commercial, full name for residential)
     */
    protected function getClientName(): string
    {
        if ($this->client_type === 'commercial') {
            return $this->business_name ?? 'Unnamed Business';
        }

        return trim($this->first_name . ' ' . $this->last_name) ?: 'Unnamed Client';
    }

    /**
     * Get contact person name (contact person for commercial, full name for residential)
     */
    protected function getContactName(): ?string
    {
        if ($this->client_type === 'commercial') {
            return $this->contact_person_name;
        }

        return trim($this->first_name . ' ' . $this->last_name) ?: null;
    }
}
