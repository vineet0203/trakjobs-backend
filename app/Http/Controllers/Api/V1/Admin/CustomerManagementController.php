<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerManagementController extends BaseController
{
    /**
     * PATCH /api/v1/admin/customers/{id}/reset-password
     * Reset customer password manually
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return $this->notFoundResponse('Customer not found');
        }

        $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $newPassword = $request->input('password');

        try {
            $customer->update([
                'password' => Hash::make($newPassword)
            ]);

            Mail::raw("Hello,

An administrator has reset your password for TrakJobs Customer Portal. Please login and change your password immediately.

If you did not request this, contact support immediately.", function ($message) use ($customer) {
                $message->to($customer->email)->subject('TrakJobs - Admin Password Reset');
            });

            return $this->successResponse(null, 'Customer password updated successfully and email sent');
        } catch (\Exception $e) {
            Log::error('Failed to reset customer password', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to reset password: ' . $e->getMessage(), 500);
        }
    }
}
