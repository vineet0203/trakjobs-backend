<?php
// app/Services/Clients/ClientCreationService.php

namespace App\Services\Clients;

use App\Models\Client;
use App\Services\File\FileValidationRules;
use App\Services\File\FileAttachmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientCreationService
{
    public function __construct(
        private FileAttachmentService $fileAttachmentService,
        private ClientAvailabilityService $availabilityService
    ) {}

    /**
     * Create a new client
     */
    public function create(array $data, int $createdBy): Client
    {
        DB::beginTransaction();

        try {
            Log::info('=== CLIENT CREATION START ===', [
                'data_keys' => array_keys($data),
                'has_logo_temp_id' => isset($data['logo_temp_id']),
                'logo_temp_id' => $data['logo_temp_id'] ?? null,
                'created_by' => $createdBy
            ]);

            // Extract availability data if present
            $availabilityData = $data['availability_schedule'] ?? null;
            
            // Prepare the data for database insertion (flatten nested objects)
            $createData = [];

            // Handle address object
            if (isset($data['address']) && is_array($data['address'])) {
                $createData = array_merge($createData, [
                    'address_line_1' => $data['address']['address_line_1'] ?? null,
                    'address_line_2' => $data['address']['address_line_2'] ?? null,
                    'city' => $data['address']['city'] ?? null,
                    'state' => $data['address']['state'] ?? null,
                    'country' => $data['address']['country'] ?? null,
                    'zip_code' => $data['address']['zip_code'] ?? null,
                ]);
                
                Log::info('Address data extracted', [
                    'address_line_1' => $data['address']['address_line_1'] ?? null,
                    'address_line_2' => $data['address']['address_line_2'] ?? null,
                    'city' => $data['address']['city'] ?? null,
                ]);
            }

            // Handle payment object
            if (isset($data['payment']) && is_array($data['payment'])) {
                $createData = array_merge($createData, [
                    'payment_term' => $data['payment']['payment_term'] ?? null,
                    'preferred_currency' => isset($data['payment']['preferred_currency']) 
                        ? strtolower($data['payment']['preferred_currency']) 
                        : null,
                    'billing_name' => $data['payment']['billing_name'] ?? null,
                ]);
                
                Log::info('Payment data extracted', [
                    'payment_term' => $data['payment']['payment_term'] ?? null,
                    'preferred_currency' => $data['payment']['preferred_currency'] ?? null,
                ]);
            }

            // Handle tax object
            if (isset($data['tax']) && is_array($data['tax'])) {
                $isTaxApplicable = filter_var($data['tax']['is_tax_applicable'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $createData['is_tax_applicable'] = $isTaxApplicable;
                $createData['tax_percentage'] = $isTaxApplicable
                    ? (int) ($data['tax']['tax_percentage'] ?? 0)
                    : 0;
                Log::info('Tax data extracted', [
                    'is_tax_applicable' => $isTaxApplicable,
                    'tax_percentage' => $createData['tax_percentage']
                ]);
            } elseif (array_key_exists('is_tax_applicable', $data) || array_key_exists('tax_percentage', $data)) {
                $isTaxApplicable = filter_var($data['is_tax_applicable'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $createData['is_tax_applicable'] = $isTaxApplicable;
                $createData['tax_percentage'] = $isTaxApplicable ? (int) ($data['tax_percentage'] ?? 0) : 0;
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
                'vendor_id',
                'created_by',
                'status' => 'active', // Default status
            ];

            foreach ($flatFields as $key => $default) {
                $fieldName = is_numeric($key) ? $default : $key;
                $defaultValue = is_numeric($key) ? null : $default;
                
                if (array_key_exists($fieldName, $data)) {
                    $createData[$fieldName] = $data[$fieldName];
                } elseif (isset($defaultValue)) {
                    $createData[$fieldName] = $defaultValue;
                }
            }

            // ✅ FIX: Explicitly add logo_temp_id to createData if it exists in original data
            if (isset($data['logo_temp_id'])) {
                $createData['logo_temp_id'] = $data['logo_temp_id'];
                Log::info('✅ Logo temp_id added to createData', [
                    'logo_temp_id' => $data['logo_temp_id']
                ]);
            } else {
                Log::info('ℹ️ No logo_temp_id in request data');
            }

            // Add created_by
            $createData['created_by'] = $createdBy;
            $createData['updated_by'] = $createdBy;

            // Remove any null values that might cause issues
            $createData = array_filter($createData, function($value) {
                return !is_null($value);
            });

            Log::info('Processed create data', [
                'create_data_keys' => array_keys($createData),
                'has_address' => isset($createData['address_line_1']),
                'has_payment' => isset($createData['payment_term']),
                'has_logo_temp_id' => isset($createData['logo_temp_id']) ? 'yes' : 'no',
                'logo_temp_id' => $createData['logo_temp_id'] ?? null
            ]);

            // ✅ FIX: Use $createData instead of $data for logo upload
            if (isset($createData['logo_temp_id'])) {
                Log::info('🖼️ Attempting to attach logo', [
                    'temp_id' => $createData['logo_temp_id']
                ]);
                
                $errors = $this->fileAttachmentService->attachFile(
                    data: $createData,  // Changed from $data to $createData
                    tempIdField: 'logo_temp_id',
                    pathField: 'logo_path',
                    destinationPath: 'clients/logos',
                    allowedMimeTypes: FileValidationRules::getAllowedMimeTypes('images'),
                    maxSizeKb: FileValidationRules::getSizeLimits('images'),
                    customFilename: $this->generateLogoFilename(
                        $data['business_name']  // Keep using $data for business_name
                            ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))
                            ?? 'client'
                    ),
                    keepOriginalName: false
                );

                if (!empty($errors)) {
                    throw new \Exception(implode(', ', $errors['logo_temp_id'] ?? []));
                }
                
                Log::info('✅ Logo attached successfully', [
                    'logo_path' => $createData['logo_path'] ?? null
                ]);
            } else {
                Log::info('ℹ️ No logo to attach - logo_temp_id not present');
            }

            Log::info('Creating client with data', [
                'data_keys' => array_keys($createData),
                'has_logo_path' => isset($createData['logo_path']),
                'logo_path' => $createData['logo_path'] ?? null
            ]);

            $client = Client::create($createData);

            // Create availability schedule if data provided
            if (!empty($availabilityData)) {
                // Ensure has_lunch_break is boolean
                if (isset($availabilityData['has_lunch_break'])) {
                    $availabilityData['has_lunch_break'] = filter_var($availabilityData['has_lunch_break'], FILTER_VALIDATE_BOOLEAN);
                }
                
                $this->availabilityService->createSchedule($client, $availabilityData, $createdBy);
                Log::info('Availability schedule created for client', [
                    'client_id' => $client->id,
                    'available_days' => $availabilityData['available_days'] ?? []
                ]);
            }

            DB::commit();

            // Reload with relationships
            $client->load('availabilitySchedules');

            Log::info('Client created successfully', [
                'client_id' => $client->id,
                'vendor_id' => $client->vendor_id,
                'business_name' => $client->business_name,
                'has_logo' => !empty($client->logo_path),
                'logo_path' => $client->logo_path,
                'created_by' => $createdBy,
                'has_schedule' => !empty($availabilityData),
                'address_line_2' => $client->address_line_2,
            ]);

            return $client;
        } catch (\Exception $e) {
            DB::rollBack();

            // Cleanup any temporary uploads if creation failed
            if (isset($data['logo_temp_id'])) {
                Log::info('Cleaning up temporary upload due to failure', [
                    'temp_id' => $data['logo_temp_id']
                ]);
                $this->fileAttachmentService->cleanupUnusedTemporaryUpload($data['logo_temp_id']);
            }

            Log::error('Failed to create client', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => array_keys($data),
                'created_by' => $createdBy,
            ]);
            throw $e;
        }
    }

    /**
     * Generate logo filename
     */
    private function generateLogoFilename(string $businessName): string
    {
        $slug = \Illuminate\Support\Str::slug($businessName);
        $timestamp = time();
        $random = \Illuminate\Support\Str::random(6);
        return "logo_{$slug}_{$random}_{$timestamp}.png";
    }

    /**
     * Validate if client with same email exists for vendor
     */
    public function validateEmailExists(int $vendorId, string $email): bool
    {
        return Client::where('vendor_id', $vendorId)
            ->where('email', $email)
            ->exists();
    }
}