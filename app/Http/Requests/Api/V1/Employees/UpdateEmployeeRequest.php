<?php

namespace App\Http\Requests\Api\V1\Employees;

use App\Services\File\FileValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendorId = auth()->user()->vendor_id;
        $employeeId = $this->route('employeeId');

        $employeeExists = DB::table('employees')
            ->where('vendor_id', $vendorId)
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$employeeExists) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Employee not found.',
                    'timestamp' => now()->toIso8601String(),
                    'code' => 404,
                    'error_code' => 'EMPLOYEE_NOT_FOUND'
                ], 404)
            );
        }

        return true;
    }

    public function rules(): array
    {
        $vendorId = auth()->user()->vendor_id;
        $employeeId = $this->route('employeeId');

        return [
            /*
            |--------------------------------------------------------------------------
            | Basic Information
            |--------------------------------------------------------------------------
            */
            'employee_id' => [
                'nullable',
                'string',
                'max:191', // Changed from 50 to 191 to match frontend
                'regex:/^[A-Z0-9-]+$/', // Added regex to match frontend validation
                Rule::unique('employees')
                    ->where(fn($q) => $q->where('vendor_id', $vendorId)
                        ->where('id', '!=', $employeeId)
                        ->whereNull('deleted_at'))
            ],
            'first_name' => 'nullable|string|max:191',
            'last_name' => 'nullable|string|max:191',
            'date_of_birth' => 'nullable|date|before_or_equal:today', // Changed from 'before:today' to match frontend
            'gender' => 'nullable|in:male,female,other',

            /*
            |--------------------------------------------------------------------------
            | Contact Details
            |--------------------------------------------------------------------------
            */
            'email' => [
                'nullable',
                'email',
                'max:191',
                Rule::unique('employees')
                    ->where(fn($q) => $q->where('vendor_id', $vendorId)
                        ->where('id', '!=', $employeeId)
                        ->whereNull('deleted_at'))
            ],
            'mobile_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',

            /*
            |--------------------------------------------------------------------------
            | Official Details
            |--------------------------------------------------------------------------
            */
            'designation' => 'nullable|string|max:191',
            'department' => 'nullable|string|max:191',
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
            'is_active' => $this->boolean('is_active', true), // Added default true
            'remove_profile_photo' => $this->boolean('remove_profile_photo', false), // Added default false
        ];

        // Handle empty strings for nullable fields
        if ($this->has('employee_id') && $this->input('employee_id') === '') {
            $data['employee_id'] = null;
        }

        if ($this->has('first_name') && $this->input('first_name') === '') {
            $data['first_name'] = null;
        }

        if ($this->has('last_name') && $this->input('last_name') === '') {
            $data['last_name'] = null;
        }

        if ($this->has('date_of_birth') && $this->input('date_of_birth') === '') {
            $data['date_of_birth'] = null;
        }

        if ($this->has('email') && $this->input('email') === '') {
            $data['email'] = null;
        }

        if ($this->has('mobile_number') && $this->input('mobile_number') === '') {
            $data['mobile_number'] = null;
        }

        if ($this->has('address') && $this->input('address') === '') {
            $data['address'] = null;
        }

        if ($this->has('designation') && $this->input('designation') === '') {
            $data['designation'] = null;
        }

        if ($this->has('department') && $this->input('department') === '') {
            $data['department'] = null;
        }

        if ($this->has('reporting_manager_id') && $this->input('reporting_manager_id') === '') {
            $data['reporting_manager_id'] = null;
        }

        $this->merge($data);
    }

    public function messages(): array
    {
        return [
            'employee_id.unique' => 'Employee ID already exists.',
            'employee_id.regex' => 'Employee ID must contain only uppercase letters, numbers, and hyphens.',
            'employee_id.max' => 'Employee ID must be at most 191 characters.',

            'email.unique' => 'Email already exists.',

            'reporting_manager_id.exists' => 'Selected reporting manager does not exist.',
            'reporting_manager_id.min' => 'Invalid reporting manager.',

            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',

            'gender.in' => 'Invalid gender selection.',

            'role.in' => 'Invalid role selection.',

            'profile_photo_temp_id.regex' => 'Invalid photo upload ID.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'Employee ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'date_of_birth' => 'Date of Birth',
            'gender' => 'Gender',
            'email' => 'Email',
            'mobile_number' => 'Mobile Number',
            'address' => 'Address',
            'designation' => 'Designation',
            'department' => 'Department',
            'reporting_manager_id' => 'Reporting Manager',
            'role' => 'Role',
            'is_active' => 'Active Status',
            'profile_photo_temp_id' => 'Profile Photo',
        ];
    }
}
