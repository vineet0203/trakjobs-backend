<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;

class RegistrationValidationService
{
    public function __construct(
        private PasswordService $passwordService
    ) {
    }

    public function validateRegistrationData(array $data): void
    {
        $this->validateRequiredFields($data);
        $this->validateBusinessData($data);
        $this->validatePersonalData($data);
        $this->validateUniqueness($data);
        $this->validatePasswordStrength($data['password']);
        $this->validateTermsAccepted($data);
    }

    private function validateRequiredFields(array $data): void
    {
        $required = [
            'business_name',
            'website_name',
            'full_name',
            'email',
            'mobile_number',
            'business_type',
            'service_category',
            'service_sub_category',
            'availability_type',
            'office_start_time',
            'office_end_time',
            'terms_accepted',
            'password',
        ];

        $missing = [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new \Exception("Required fields are missing: " . implode(', ', $missing));
        }

        if (
            ($data['service_category'] ?? null) === 'custom'
            && empty($data['service_category_custom'])
        ) {
            throw new \Exception('New main service is required');
        }

        if (
            ($data['service_sub_category'] ?? null) === 'custom'
            && empty($data['service_sub_category_custom'])
        ) {
            throw new \Exception('New sub-service is required');
        }

        if (($data['availability_type'] ?? null) === 'custom') {
            $days = $data['availability_days'] ?? [];
            if (!is_array($days) || count($days) < 1) {
                throw new \Exception('Availability days are required');
            }
        }
    }

    private function validateBusinessData(array $data): void
    {
        // Validate business name format
        if (strlen($data['business_name']) < 2) {
            throw new \Exception("Business name must be at least 2 characters long");
        }

        // Validate website format
        if (!filter_var($data['website_name'], FILTER_VALIDATE_URL)) {
            throw new \Exception("Please enter a valid website URL");
        }

        if (!in_array($data['business_type'], ['commercial', 'residential'], true)) {
            throw new \Exception("Invalid business type. Must be commercial or residential");
        }

        $availabilityType = $data['availability_type'] ?? null;
        if (!in_array($availabilityType, ['mon_fri', 'full_week', 'custom'], true)) {
            throw new \Exception('Invalid availability type. Must be mon_fri, full_week, or custom');
        }

        if ($availabilityType === 'custom') {
            $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $days = $data['availability_days'] ?? [];
            foreach ($days as $day) {
                if (!in_array($day, $validDays, true)) {
                    throw new \Exception('Invalid availability day');
                }
            }
        }

        if (empty($data['office_start_time']) || empty($data['office_end_time'])) {
            throw new \Exception('Office start and end time are required');
        }
    }

    private function validatePersonalData(array $data): void
    {
        // Validate full name
        if (strlen($data['full_name']) < 2) {
            throw new \Exception("Full name must be at least 2 characters long");
        }

        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Please enter a valid email address");
        }

        // Validate mobile number format (basic validation)
        if (!preg_match('/^[0-9\s\+\-\(\)]{10,20}$/', $data['mobile_number'])) {
            throw new \Exception("Please enter a valid mobile number (10-20 digits)");
        }

        // Split full name and validate parts
        $nameParts = explode(' ', $data['full_name'], 2);
        if (count($nameParts) < 2) {
            Log::warning('Full name may not have both first and last name', ['full_name' => $data['full_name']]);
        }

        if (empty($nameParts[0])) {
            throw new \Exception("First name is required");
        }
    }

    private function validateUniqueness(array $data): void
    {
        // Check if business name already exists
        if (Vendor::where('business_name', $data['business_name'])->exists()) {
            throw new \Exception('Business name "' . $data['business_name'] . '" already exists');
        }

        // Check if email already exists
        if (User::where('email', $data['email'])->exists()) {
            throw new \Exception('Email "' . $data['email'] . '" is already registered');
        }

        // Check if email already exists as employee
        if (\App\Models\Employee::where('email', $data['email'])->exists()) {
            throw new \Exception('This email is already registered as an Employee. Vendor and Employee cannot be the same.');
        }

        // Check if mobile number already exists in vendor table (optional)
        if (Vendor::where('mobile_number', $data['mobile_number'])->exists()) {
            throw new \Exception('Mobile number "' . $data['mobile_number'] . '" is already registered');
        }
    }

    private function validatePasswordStrength(string $password): void
    {
        $this->passwordService->validatePasswordStrength($password);
    }

    private function validateTermsAccepted(array $data): void
    {
        if (!isset($data['terms_accepted']) || !$data['terms_accepted']) {
            throw new \Exception('You must accept the terms and conditions to register');
        }
    }

    /**
     * Validate complete registration data (comprehensive check)
     */
    public function validateCompleteRegistration(array $data): void
    {
        try {
            // Step 1: Basic required fields
            $this->validateRequiredFields($data);

            // Step 2: Business information
            $this->validateBusinessData($data);

            // Step 3: Personal information
            $this->validatePersonalData($data);

            // Step 4: Terms acceptance
            $this->validateTermsAccepted($data);

            // Step 6: Password strength
            $this->validatePasswordStrength($data['password']);

            // Step 7: Uniqueness checks (last because it's DB-intensive)
            $this->validateUniqueness($data);

        } catch (\Exception $e) {
            Log::error('Registration validation failed', [
                'error' => $e->getMessage(),
                'data_keys' => array_keys($data)
            ]);
            throw $e;
        }
    }
}