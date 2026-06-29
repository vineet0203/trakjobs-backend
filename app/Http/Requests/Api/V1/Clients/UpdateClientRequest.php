<?php

namespace App\Http\Requests\Api\V1\Clients;

use App\Services\File\FileValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Get vendor_id from authenticated user
        $vendorId = auth()->user()->vendor_id;
        $clientId = $this->route('clientId');

        $clientExists = DB::table('clients')
            ->where('vendor_id', $vendorId)
            ->where('id', $clientId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$clientExists) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Client not found.',
                    'timestamp' => now()->toIso8601String(),
                    'code' => 404,
                    'error_code' => 'CLIENT_NOT_FOUND'
                ], 404)
            );
        }

        return true;
    }

    public function rules(): array
    {
        // Get vendor_id from authenticated user
        $vendorId = auth()->user()->vendor_id;
        $clientId = $this->route('clientId');
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $allowedCategories = \App\Models\ServiceCategory::pluck('slug')->toArray();

        $rules = [
            /*
            |--------------------------------------------------------------------------
            | Client Type
            |--------------------------------------------------------------------------
            */
            'client_type' => 'sometimes|in:commercial,residential',

            /*
            |--------------------------------------------------------------------------
            | Contact Fields (common for both types)
            |--------------------------------------------------------------------------
            */
            'email' => [
                'nullable',
                'email',
                'max:191',
                Rule::unique('clients')
                    ->where(fn($q) => $q->where('vendor_id', $vendorId)
                        ->where('id', '!=', $clientId)
                        ->whereNull('deleted_at'))
            ],
            'mobile_number' => 'nullable|string|max:20',
            'alternate_mobile_number' => 'nullable|string|max:20',

            /*
            |--------------------------------------------------------------------------
            | Address Object (common for both types)
            |--------------------------------------------------------------------------
            */
            'address' => 'nullable|array',
            'address.address_line_1' => 'nullable|string|max:191',
            'address.address_line_2' => 'nullable|string|max:191',
            'address.city' => 'nullable|string|max:191',
            'address.state' => 'nullable|string|max:191',
            'address.country' => 'nullable|string|max:191',
            'address.zip_code' => 'nullable|string|max:20',

            /*
            |--------------------------------------------------------------------------
            | Residential Fields (only allowed if residential)
            |--------------------------------------------------------------------------
            */
            'first_name' => [
                'nullable',
                'string',
                'max:191',
                'exclude_unless:client_type,residential'
            ],
            'last_name' => [
                'nullable',
                'string',
                'max:191',
                'exclude_unless:client_type,residential'
            ],

            /*
            |--------------------------------------------------------------------------
            | Commercial Business Fields (only allowed if commercial)
            |--------------------------------------------------------------------------
            */
            'business_name' => [
                'nullable',
                'string',
                'max:191',
                'exclude_unless:client_type,commercial',
                Rule::unique('clients')
                    ->where(fn($q) => $q->where('vendor_id', $vendorId)
                        ->where('id', '!=', $clientId)
                        ->whereNull('deleted_at'))
            ],

            'business_type' => [
                'nullable',
                'exclude_unless:client_type,commercial',
                'in:sole_proprietor,partnership,private_limited,public_limited,llp,cooperative,ngo,government,other'
            ],

            'industry' => [
                'nullable',
                'exclude_unless:client_type,commercial',
                'in:technology,retail,healthcare,finance,manufacturing,construction,education,hospitality,transportation,other'
            ],

            'business_registration_number' => 'nullable|string|max:191|exclude_unless:client_type,commercial',
            'contact_person_name' => 'nullable|string|max:191|exclude_unless:client_type,commercial',
            'designation' => [
                'nullable',
                'exclude_unless:client_type,commercial',
                'in:owner,ceo,manager,director,accountant,admin,employee,other'
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Object (Commercial Only)
            |--------------------------------------------------------------------------
            */
            'payment' => 'nullable|array|exclude_unless:client_type,commercial',
            'payment.billing_name' => 'nullable|string|max:191',
            'payment.payment_term' => [
                'nullable',
                'exclude_unless:client_type,commercial',
                'in:due_on_receipt,net_7,net_15,net_30,net_45,net_60'
            ],
            'payment.preferred_currency' => [
                'nullable',
                'string',
                'size:3',
                'exclude_unless:client_type,commercial',
                'in:inr,usd,eur,gbp,aed,sgd,cad,aud'
            ],

            /*
            |--------------------------------------------------------------------------
            | Tax Object
            |--------------------------------------------------------------------------
            */
            'tax' => 'nullable|array',
            'tax.is_tax_applicable' => 'nullable|boolean',
            'tax.tax_percentage' => 'required_if:tax.is_tax_applicable,true|integer|in:0,5,12,18,28',

            /*
            |--------------------------------------------------------------------------
            | Additional Details
            |--------------------------------------------------------------------------
            */
            'website_url' => 'nullable|url|max:191',
            'service_category' => ['sometimes', 'required', Rule::in($allowedCategories)],
            'service_sub_category' => 'sometimes|required|string|max:100',
            'notes' => 'nullable|string',

            /*
            |--------------------------------------------------------------------------
            | Logo Upload
            |--------------------------------------------------------------------------
            */
            'logo_temp_id' => [
                'nullable',
                'string',
                'regex:/^tmp_[a-zA-Z0-9]+_[0-9]+$/'
            ],
            'remove_logo' => 'nullable|boolean',

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */
            'status' => 'nullable|in:active,inactive,suspended,archived',

            /*
            |--------------------------------------------------------------------------
            | Availability Schedule
            |--------------------------------------------------------------------------
            */
            'availability_schedule' => 'nullable|array',
            'availability_schedule.available_days' => 'nullable|array|min:1',
            'availability_schedule.available_days.*' => 'string|in:' . implode(',', $days),
            'availability_schedule.preferred_start_time' => 'nullable|date_format:H:i',
            'availability_schedule.preferred_end_time' => [
                'nullable',
                'date_format:H:i',
                'after:availability_schedule.preferred_start_time'
            ],
            'availability_schedule.has_lunch_break' => 'nullable|boolean',
            'availability_schedule.lunch_start' => [
                'nullable',
                'date_format:H:i',
                'required_if:availability_schedule.has_lunch_break,true'
            ],
            'availability_schedule.lunch_end' => [
                'nullable',
                'date_format:H:i',
                'required_if:availability_schedule.has_lunch_break,true',
                'after:availability_schedule.lunch_start'
            ],
            'availability_schedule.notes' => 'nullable|string|max:1000',
        ];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $rawLogoId = $this->input('logo_temp_id') ?: $this->input('logoTempId');

        // Prepare base data
        $data = [
            'logo_temp_id' => $rawLogoId ?: null,
            'website_url' => $this->prepareWebsiteUrl($this->website_url),
        ];

        // Handle tax object
        if ($this->has('tax') && is_array($this->tax)) {
            $currentApplicable = filter_var($this->input('tax.is_tax_applicable', false), FILTER_VALIDATE_BOOLEAN);
            $data['is_tax_applicable'] = $currentApplicable;
            $data['tax_percentage'] = $currentApplicable
                ? (int) ($this->input('tax.tax_percentage', 0))
                : 0;
        }

        // Handle payment currency case
        if ($this->has('payment') && isset($this->payment['preferred_currency'])) {
            $payment = $this->payment;
            $payment['preferred_currency'] = strtolower($payment['preferred_currency']);
            $data['payment'] = $payment;
        }

        // Handle availability schedule
        if ($this->has('availability_schedule')) {
            $schedule = is_array($this->availability_schedule)
                ? $this->availability_schedule
                : json_decode($this->availability_schedule, true);

            if (isset($schedule['has_lunch_break'])) {
                $schedule['has_lunch_break'] = filter_var($schedule['has_lunch_break'], FILTER_VALIDATE_BOOLEAN);
            }

            $data['availability_schedule'] = $schedule;
        }

        $this->merge($data);
    }

    private function prepareWebsiteUrl(?string $url): ?string
    {
        if (!$url || str_starts_with($url, 'tmp_')) {
            return null;
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return 'https://' . $url;
        }

        return $url;
    }

    public function messages(): array
    {
        return [
            'business_name.unique' => 'Business name already exists.',
            'email.unique' => 'Email already exists.',
            'tax.tax_percentage.in' => 'Tax percentage must be one of 0, 5, 12, 18, or 28.',
            'logo_temp_id.regex' => 'Invalid logo upload ID.',
            'availability_schedule.preferred_end_time.after' => 'End time must be after start time.',
            'availability_schedule.lunch_end.after' => 'Lunch end time must be after lunch start time.',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($this->has('logo_temp_id')) {
            $validated['logo_temp_id'] = $this->input('logo_temp_id');
        }

        return $validated;
    }
}