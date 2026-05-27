<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Business/Vendor Information - matching migration columns
            'business_name' => 'required|string|max:191|unique:vendors,business_name',
            'website_name' => 'required|string|url|max:191',
            'business_type' => 'required|string|in:commercial,residential',

            // Services
            'service_category' => 'required|string|max:191',
            'service_category_custom' => 'nullable|string|max:191|required_if:service_category,custom',
            'service_sub_category' => 'required|string|max:191',
            'service_sub_category_custom' => 'nullable|string|max:191|required_if:service_sub_category,custom',

            // Availability
            'availability_type' => 'required|string|in:mon_fri,full_week,custom',
            'availability_days' => 'nullable|array|required_if:availability_type,custom|min:1',
            'availability_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'office_start_time' => 'required|date_format:H:i',
            'office_end_time' => 'required|date_format:H:i|after:office_start_time',

            // Personal Information
            'full_name' => 'required|string|max:191',
            'email' => 'required|email|max:191|unique:users,email',
            'mobile_number' => 'required|string|max:50',

            // Password
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
            ],

            // Terms agreement
            'terms_accepted' => 'required|boolean|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'Business name is required',
            'business_name.unique' => 'A business with this name already exists.',
            'website_name.required' => 'Website URL is required',
            'website_name.url' => 'Please enter a valid website URL',
            'full_name.required' => 'Your full name is required',
            'email.required' => 'Email address is required',
            'email.email' => 'Please enter a valid email address',
            'email.unique' => 'This email address is already registered.',
            'mobile_number.required' => 'Mobile number is required',
            'business_type.required' => 'Business type is required',
            'service_category.required' => 'Main service category is required',
            'service_category_custom.required_if' => 'New main service is required',
            'service_sub_category.required' => 'Service subcategory is required',
            'service_sub_category_custom.required_if' => 'New sub-service is required',
            'availability_type.required' => 'Availability is required',
            'availability_days.required_if' => 'Availability days are required',
            'availability_days.min' => 'Select at least one available day',
            'office_start_time.required' => 'Office start time is required',
            'office_end_time.required' => 'Office end time is required',
            'office_end_time.after' => 'Office end time must be after start time',
            'password.required' => 'Password is required',
            'password.confirmed' => 'Password confirmation does not match',
            'terms_accepted.required' => 'You must accept the terms and conditions',
            'terms_accepted.accepted' => 'You must accept the terms and conditions',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Split full_name into first_name and last_name for user creation
        $fullName = $this->full_name ?? '';
        $nameParts = explode(' ', $fullName, 2);

        $this->merge([
            'business_name' => $this->businessName ?? $this->business_name,
            'website_name' => $this->websiteName ?? $this->website_name,
            'full_name' => $this->fullName ?? $this->full_name,
            'mobile_number' => $this->mobileNumber ?? $this->mobile_number,
            'first_name' => $nameParts[0] ?? '',
            'last_name' => $nameParts[1] ?? '',
            'terms_accepted' => filter_var($this->terms_accepted ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Get validated data with processed fields
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // Add processed fields
        $validated['first_name'] = $this->input('first_name');
        $validated['last_name'] = $this->input('last_name');

        return $validated;
    }
}
