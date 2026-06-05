<?php

namespace App\Http\Requests\Api\V1\Employees;

use App\Services\File\FileValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreateEmployeeRequest extends FormRequest
{
    private ?string $existingRole = null;
    private ?string $existingRoleMessage = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vendorId = auth()->user()->vendor_id;

        return [
            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */
            'first_name' => 'required|string|max:191',
            'last_name' => 'nullable|string|max:191',
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'gender' => 'nullable|in:male,female,other',

            /*
            |--------------------------------------------------------------------------
            | Contact Details
            |--------------------------------------------------------------------------
            */
            'email' => [
                'required',
                'email',
                'max:191',
                Rule::unique('employees')->where(
                    fn($q) => $q->where('vendor_id', $vendorId)
                ),
                function ($attribute, $value, $fail) {
                    if (DB::table('customers')->where('email', $value)->exists()) {
                        $this->existingRole = 'customer';
                        $this->existingRoleMessage = 'This email is already registered as a Customer. Please use a different email.';
                        $fail($this->existingRoleMessage);
                    }
                },
            ],
            'mobile_number' => 'required|string|max:20',
            'address' => 'nullable|string|max:500',

            /*
            |--------------------------------------------------------------------------
            | Official Details
            |--------------------------------------------------------------------------
            */
            'designation' => 'required|string|max:191',
            'department' => 'required|string|max:191',
            'reporting_manager_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(
                    fn($q) => $q->where('vendor_id', $vendorId)
                        ->where('is_active', true)
                ),
            ],
            'role' => 'nullable|in:admin,manager,employee,supervisor',
            'is_active' => 'nullable|boolean',

            /*
            |--------------------------------------------------------------------------
            | Profile Photo
            |--------------------------------------------------------------------------
            */
            'profile_photo_temp_id' => [
                'nullable',
                'string',
                'regex:/^tmp_[a-zA-Z0-9]+_[0-9]+$/'
            ],
            'remove_profile_photo' => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $rawPhotoId = $this->input('profile_photo_temp_id') ?: $this->input('profilePhotoTempId');

        $data = [
            'profile_photo_temp_id' => $rawPhotoId ?: null,
            'is_active' => $this->boolean('is_active', true),
            'remove_profile_photo' => $this->boolean('remove_profile_photo', false),
        ];

        // Handle empty strings for nullable fields
        if ($this->has('last_name') && $this->input('last_name') === '') {
            $data['last_name'] = null;
        }
        
        if ($this->has('date_of_birth') && $this->input('date_of_birth') === '') {
            $data['date_of_birth'] = null;
        }
        
        if ($this->has('address') && $this->input('address') === '') {
            $data['address'] = null;
        }
        
        if ($this->has('reporting_manager_id') && $this->input('reporting_manager_id') === '') {
            $data['reporting_manager_id'] = null;
        }

        $this->merge($data);
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'first_name.max' => 'First name must be at most 191 characters.',
            
            'last_name.max' => 'Last name must be at most 191 characters.',
            
            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
            
            'gender.in' => 'Invalid gender selection.',
            
            'email.required' => 'Email is required.',
            'email.email' => 'Invalid email format.',
            'email.unique' => 'Email already exists.',
            'email.max' => 'Email must be at most 191 characters.',
            
            'mobile_number.required' => 'Mobile number is required.',
            'mobile_number.max' => 'Mobile number must be at most 20 characters.',
            
            'address.max' => 'Address must be at most 500 characters.',
            
            'designation.required' => 'Designation is required.',
            'designation.max' => 'Designation must be at most 191 characters.',
            
            'department.required' => 'Department is required.',
            'department.max' => 'Department must be at most 191 characters.',
            
            'reporting_manager_id.integer' => 'Invalid reporting manager.',
            'reporting_manager_id.min' => 'Invalid reporting manager.',
            'reporting_manager_id.exists' => 'Selected reporting manager does not exist.',
            
            'role.in' => 'Invalid role selection.',
            
            'profile_photo_temp_id.regex' => 'Invalid photo upload ID.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->existingRole && $this->existingRoleMessage) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => $this->existingRoleMessage,
                'existing_role' => $this->existingRole,
                'errors' => $validator->errors(),
                'timestamp' => now()->toIso8601String(),
                'code' => 422,
            ], 422));
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation errors',
            'errors' => $validator->errors(),
            'timestamp' => now()->toIso8601String(),
            'code' => 422,
        ], 422));
    }
}