<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Services\File\FileValidationRules;
use App\Services\File\FileAttachmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientUpdateService
{
    public function __construct(
        private FileAttachmentService $fileAttachmentService,
        private ClientAvailabilityService $availabilityService
    ) {}

    /**
     * Update an existing client
     */
    public function update(Client $client, array $data, int $updatedBy): Client
    {
        DB::beginTransaction();

        try {
            Log::info('=== CLIENT UPDATE START ===', [
                'client_id' => $client->id,
                'data_keys' => array_keys($data),
                'has_logo_temp_id' => isset($data['logo_temp_id']),
                'logo_temp_id' => $data['logo_temp_id'] ?? null,
                'has_remove_logo' => isset($data['remove_logo']),
                'remove_logo' => $data['remove_logo'] ?? false,
                'updated_by' => $updatedBy
            ]);

            // Extract nested objects before processing
            $updateData = [];

            // Handle address object
            if (isset($data['address']) && is_array($data['address'])) {
                $updateData = array_merge($updateData, [
                    'address_line_1' => $data['address']['address_line_1'] ?? null,
                    'address_line_2' => $data['address']['address_line_2'] ?? null,
                    'city' => $data['address']['city'] ?? null,
                    'state' => $data['address']['state'] ?? null,
                    'country' => $data['address']['country'] ?? null,
                    'zip_code' => $data['address']['zip_code'] ?? null,
                ]);
                
                Log::info('Address data extracted', [
                    'address_line_2' => $data['address']['address_line_2'] ?? null
                ]);
            }

            // Handle payment object
            if (isset($data['payment']) && is_array($data['payment'])) {
                $updateData = array_merge($updateData, [
                    'payment_term' => $data['payment']['payment_term'] ?? null,
                    'preferred_currency' => isset($data['payment']['preferred_currency']) 
                        ? strtolower($data['payment']['preferred_currency']) 
                        : null,
                    'billing_name' => $data['payment']['billing_name'] ?? null,
                ]);
            }

            // Handle tax object
            if (isset($data['tax']) && is_array($data['tax'])) {
                $isTaxApplicable = filter_var($data['tax']['is_tax_applicable'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $updateData['is_tax_applicable'] = $isTaxApplicable;
                $updateData['tax_percentage'] = $isTaxApplicable
                    ? (int) ($data['tax']['tax_percentage'] ?? 0)
                    : 0;
            } elseif (array_key_exists('is_tax_applicable', $data) || array_key_exists('tax_percentage', $data)) {
                $isTaxApplicable = filter_var($data['is_tax_applicable'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $updateData['is_tax_applicable'] = $isTaxApplicable;
                $updateData['tax_percentage'] = $isTaxApplicable ? (int) ($data['tax_percentage'] ?? 0) : 0;
            }

            // Add other flat fields directly
            $flatFields = [
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
                'website_url',
                'service_category', 'service_sub_category',
                'notes',
                'status',
                'logo_temp_id',
                'remove_logo'
            ];

            foreach ($flatFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $data[$field];
                }
            }

            // Add updated_by
            $updateData['updated_by'] = $updatedBy;

            // Extract availability data if present
            $availabilityData = $data['availability_schedule'] ?? null;

            Log::info('Processed update data', [
                'client_id' => $client->id,
                'update_data_keys' => array_keys($updateData),
                'has_address_line_2' => isset($updateData['address_line_2']),
                'address_line_2_value' => $updateData['address_line_2'] ?? null
            ]);

            // Handle billing address logic (if needed)
            if (isset($data['same_as_business_address']) && $data['same_as_business_address']) {
                $updateData = $this->copyBusinessAddressToBilling($updateData, $client);
            }

            // Handle logo updates using temporary upload ID
            if (isset($updateData['logo_temp_id']) || isset($updateData['remove_logo'])) {
                $errors = $this->fileAttachmentService->updateFile(
                    model: $client,
                    data: $updateData,
                    tempIdField: 'logo_temp_id',
                    pathField: 'logo_path',
                    destinationPath: 'clients/logos',
                    allowedMimeTypes: FileValidationRules::getAllowedMimeTypes('images'),
                    maxSizeKb: FileValidationRules::getSizeLimits('images'),
                    customFilename: $this->generateLogoFilename(
                        $client->business_name
                            ?? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''))
                            ?? 'client'
                    ),
                    keepOriginalName: false,
                    removeField: 'remove_logo'
                );

                if (!empty($errors)) {
                    throw new \Exception(implode(', ', $errors['logo_temp_id'] ?? []));
                }
            }

            // Remove logo_temp_id and remove_logo from update data as they're handled separately
            unset($updateData['logo_temp_id']);
            unset($updateData['remove_logo']);

            Log::info('Updating client with data', [
                'client_id' => $client->id,
                'data_keys' => array_keys($updateData),
                'has_logo_path' => isset($updateData['logo_path']),
                'logo_path' => $updateData['logo_path'] ?? null
            ]);

            $client->update($updateData);
            $client->refresh();

            // Update or create availability schedule if data provided
            if (!empty($availabilityData)) {
                $this->handleAvailabilitySchedule($client, $availabilityData, $updatedBy);
                Log::info('Availability schedule handled for client', [
                    'client_id' => $client->id
                ]);
            }

            DB::commit();

            // Reload with relationships
            $client->load('availabilitySchedules');

            Log::info('Client updated successfully', [
                'client_id' => $client->id,
                'vendor_id' => $client->vendor_id,
                'updated_fields' => array_keys($updateData),
                'has_logo' => !empty($client->logo_path),
                'logo_path' => $client->logo_path,
                'address_line_2' => $client->address_line_2,
                'updated_by' => $updatedBy,
            ]);

            return $client;
        } catch (\Exception $e) {
            DB::rollBack();

            // Cleanup any temporary uploads if update failed
            if (isset($data['logo_temp_id'])) {
                Log::info('Cleaning up temporary upload due to update failure', [
                    'temp_id' => $data['logo_temp_id']
                ]);
                $this->fileAttachmentService->cleanupUnusedTemporaryUpload($data['logo_temp_id']);
            }

            Log::error('Failed to update client', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => array_keys($data),
                'updated_by' => $updatedBy,
            ]);
            throw $e;
        }
    }

    /**
     * Generate logo filename for updates
     */
    private function generateLogoFilename(string $businessName): string
    {
        $slug = \Illuminate\Support\Str::slug($businessName);
        $timestamp = time();
        $random = \Illuminate\Support\Str::random(6);
        return "logo_{$slug}_{$random}_{$timestamp}.png";
    }

    /**
     * Handle availability schedule update or creation
     */
    private function handleAvailabilitySchedule(Client $client, array $data, int $updatedBy): void
    {
        // Check if client already has an active schedule
        $existingSchedule = $client->activeAvailabilitySchedule;

        if ($existingSchedule) {
            // Update existing schedule
            $this->availabilityService->updateSchedule($existingSchedule, $data, $updatedBy);
            Log::info('Updated existing availability schedule', [
                'client_id' => $client->id,
                'schedule_id' => $existingSchedule->id
            ]);
        } else {
            // Create new schedule
            $this->availabilityService->createSchedule($client, $data, $updatedBy);
            Log::info('Created new availability schedule', [
                'client_id' => $client->id
            ]);
        }
    }

    /**
     * Update client status
     */
    public function updateStatus(Client $client, string $status, int $updatedBy): Client
    {
        return $this->update($client, ['status' => $status], $updatedBy);
    }

    /**
     * Update client category
     */
    public function updateCategory(Client $client, string $category, int $updatedBy): Client
    {
        return $this->update($client, ['service_category' => $category], $updatedBy);
    }

    /**
     * Verify client
     */
    public function verifyClient(Client $client, int $verifiedBy): Client
    {
        DB::beginTransaction();

        try {
            $client->update([
                'is_verified' => true,
                'verified_at' => now(),
                'updated_by' => $verifiedBy,
            ]);

            DB::commit();

            Log::info('Client verified', [
                'client_id' => $client->id,
                'verified_by' => $verifiedBy,
            ]);

            return $client->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to verify client', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update client contact information
     */
    public function updateContactInfo(Client $client, array $contactInfo, int $updatedBy): Client
    {
        $allowedFields = [
            'contact_person_name',
            'designation',
            'email',
            'mobile_number',
            'alternate_mobile_number'
        ];

        $data = array_intersect_key($contactInfo, array_flip($allowedFields));

        if (!empty($data)) {
            return $this->update($client, $data, $updatedBy);
        }

        return $client;
    }

    /**
     * Update client address
     */
    public function updateAddress(Client $client, array $addressData, int $updatedBy): Client
    {
        $data = ['address' => $addressData];
        return $this->update($client, $data, $updatedBy);
    }

    /**
     * Update client payment terms
     */
    public function updatePaymentTerms(Client $client, array $paymentData, int $updatedBy): Client
    {
        $data = ['payment' => $paymentData];
        return $this->update($client, $data, $updatedBy);
    }

    /**
     * Update only client notes
     */
    public function updateNotes(Client $client, string $notes, int $updatedBy): Client
    {
        return $this->update($client, ['notes' => $notes], $updatedBy);
    }

    /**
     * Update website URL
     */
    public function updateWebsiteUrl(Client $client, string $websiteUrl, int $updatedBy): Client
    {
        // Add https:// if not present
        if (!empty($websiteUrl) && !str_starts_with($websiteUrl, 'http://') && !str_starts_with($websiteUrl, 'https://')) {
            $websiteUrl = 'https://' . $websiteUrl;
        }

        return $this->update($client, ['website_url' => $websiteUrl], $updatedBy);
    }

    /**
     * Update business registration number
     */
    public function updateBusinessRegistration(Client $client, string $registrationNumber, int $updatedBy): Client
    {
        return $this->update($client, ['business_registration_number' => $registrationNumber], $updatedBy);
    }


    /**
     * Update client logo using temporary upload ID
     */
    public function updateLogo(Client $client, string $tempId, int $updatedBy): Client
    {
        return $this->update($client, ['logo_temp_id' => $tempId], $updatedBy);
    }

    /**
     * Remove client logo
     */
    public function removeLogo(Client $client, int $updatedBy): Client
    {
        return $this->update($client, ['remove_logo' => true], $updatedBy);
    }

    /**
     * Batch update multiple fields
     */
    public function batchUpdate(Client $client, array $updates, int $updatedBy): Client
    {
        return $this->update($client, $updates, $updatedBy);
    }

    /**
     * Copy business address to billing address
     */
    private function copyBusinessAddressToBilling(array $data, Client $client): array
    {
        $data['billing_name'] = $data['billing_name'] ?? $client->billing_name ?? $client->business_name;
        // Add any other billing address fields if they exist in your schema
        
        return $data;
    }
}