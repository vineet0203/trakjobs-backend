<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Customers\CustomerLoginRequest;
use App\Http\Requests\Api\V1\Customers\CustomerSetPasswordRequest;
use App\Services\Customer\CustomerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

use App\Http\Requests\Api\V1\Customers\CustomerForgotPasswordRequest;
use App\Http\Requests\Api\V1\Customers\CustomerResetPasswordRequest;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class CustomerAuthController extends BaseController
{
    public function __construct(private CustomerAccountService $customerAccountService) {}

    public function forgotPassword(CustomerForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customer = Customer::where('email', $validated['email'])->first();

        if (!$customer) {
            return $this->notFoundResponse('Customer not found.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(30);

        DB::table('customer_password_resets')->updateOrInsert(
            ['email' => $customer->email],
            [
                'token' => $token,
                'expires_at' => $expiresAt,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $resetUrl = 'https://customer.trakjobs.com/reset-password?token=' . urlencode($token);

        try {
            Mail::send('emails.reset_password', [
                'name' => $customer->name ?: 'Customer',
                'resetUrl' => $resetUrl,
            ], function ($message) use ($customer) {
                $message->to($customer->email)
                    ->subject('Reset your password - ' . config('app.name', 'TrakJobs'));
            });
        } catch (\Throwable $exception) {
            Log::error('Customer reset password email failed to send.', [
                'email' => $customer->email,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->successResponse(['token' => $token], 'Password reset token generated successfully.');
    }

    public function resetPassword(CustomerResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $resetRow = DB::table('customer_password_resets')
            ->where('token', $validated['token'])
            ->first();

        if (!$resetRow) {
            return $this->errorResponse('Invalid reset token.', 400);
        }

        if (now()->greaterThan(Carbon::parse($resetRow->expires_at))) {
            DB::table('customer_password_resets')->where('token', $validated['token'])->delete();
            return $this->errorResponse('Reset token has expired.', 400);
        }

        $customer = Customer::where('email', $resetRow->email)->first();

        if (!$customer) {
            DB::table('customer_password_resets')->where('token', $validated['token'])->delete();
            return $this->notFoundResponse('Customer not found for reset token.');
        }

        $customer->password = Hash::make($validated['password']);
        $customer->save();

        DB::table('customer_password_resets')->where('token', $validated['token'])->delete();

        return $this->successResponse(null, 'Password reset successful.');
    }

    public function login(CustomerLoginRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $data = $this->customerAccountService->login($validated['email'], $validated['password']);

            return $this->successResponse($data, 'Customer login successful.');
        } catch (HttpException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->errorResponse('Customer login failed.', 500);
        }
    }

    public function setPassword(CustomerSetPasswordRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->customerAccountService->setPassword($validated['email'], $validated['token'], $validated['password']);

            return $this->successResponse(null, 'Password set successfully. You can now log in.');
        } catch (HttpException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->errorResponse('Failed to set password.', 500);
        }
    }

    public function me(): JsonResponse
    {
        $customer = request()->attributes->get('customer');

        $clientData = null;
        $customerEmail = is_array($customer) ? ($customer['email'] ?? null) : ($customer->email ?? null);
        if ($customerEmail) {
            $clientData = \DB::table('clients')
                ->where('email', $customerEmail)
                ->first();
        }

        $data = [
            'id'             => is_array($customer) ? ($customer['id'] ?? null) : ($customer->id ?? null),
            'name'           => is_array($customer) ? ($customer['name'] ?? null) : ($customer->name ?? null),
            'email'          => $customerEmail,
            'phone'          => is_array($customer) ? ($customer['phone'] ?? null) : ($customer->phone ?? null),
            'role'           => is_array($customer) ? ($customer['role'] ?? null) : ($customer->role ?? null),
            'status'         => is_array($customer) ? ($customer['status'] ?? null) : ($customer->status ?? null),
            'verification_status' => is_array($customer) ? ($customer['verification_status'] ?? null) : ($customer->verification_status ?? null),
            'profile_photo'  => is_array($customer) ? ($customer['profile_photo'] ?? null) : ($customer->profile_photo ?? null),
            'address_line_1'               => $clientData?->address_line_1 ?? null,
            'address_line_2'               => $clientData?->address_line_2 ?? null,
            'city'                         => $clientData?->city ?? null,
            'state'                        => $clientData?->state ?? null,
            'country'                      => $clientData?->country ?? null,
            'zip_code'                     => $clientData?->zip_code ?? null,
            'client_type'                  => $clientData?->client_type ?? null,
            'business_name'                => $clientData?->business_name ?? null,
            'business_type'                => $clientData?->business_type ?? null,
            'industry'                     => $clientData?->industry ?? null,
            'business_registration_number' => $clientData?->business_registration_number ?? null,
            'contact_person_name'          => $clientData?->contact_person_name ?? null,
            'designation'                  => $clientData?->designation ?? null,
            'alternate_mobile_number'      => $clientData?->alternate_mobile_number ?? null,
            'billing_name'                 => $clientData?->billing_name ?? null,
            'payment_term'                 => $clientData?->payment_term ?? null,
            'preferred_currency'           => $clientData?->preferred_currency ?? null,
            'is_tax_applicable'            => $clientData?->is_tax_applicable ?? null,
            'tax_percentage'               => $clientData?->tax_percentage ?? null,
            'website_url'                  => $clientData?->website_url ?? null,
            'service_category'             => $clientData?->service_category ?? null,
            'notes'                        => $clientData?->notes ?? null,
            'first_name'                   => $clientData?->first_name ?? null,
            'last_name'                    => $clientData?->last_name ?? null,
        ];

        return $this->successResponse($data, 'Customer authenticated successfully.');
    }

    public function uploadProfilePhoto(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        $customerData = $request->attributes->get('customer');
        $customer = \App\Models\Customer::find($customerData['id']);

        if (!$customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        if ($request->hasFile('photo')) {
            if ($customer->profile_photo) {
                $old = str_replace('/storage/', '', parse_url($customer->profile_photo, PHP_URL_PATH));
                Storage::disk('public')->delete($old);
            }
            $path = $request->file('photo')->store('customer-photos', 'public');
            $customer->profile_photo = config('app.url') . Storage::url($path);
            $customer->save();
        }

        return response()->json([
            'success'       => true,
            'profile_photo' => $customer->profile_photo,
        ]);
    }

}
