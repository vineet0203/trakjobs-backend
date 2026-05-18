<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Employee;
use App\Helpers\NotificationHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends BaseController
{
    public function store(Request $request): JsonResponse
    {
        $vendorId = auth()->user()?->vendor_id;

        if (!$vendorId) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'customerId' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(fn ($query) => $query->where('vendor_id', $vendorId)),
            ],
            'employeeId' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('vendor_id', $vendorId)),
            ],
            'locationId' => ['required', 'string', 'max:120'],
            'bookingDate' => ['required', 'date'],
            'bookingTime' => ['required', 'string', 'max:40'],
            'clientName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mobile' => ['required', 'regex:/^\d{7,15}$/'],
            'serviceAddress' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_status' => ['required', 'string', 'max:30'],
            'payment_method' => ['required', 'string', 'max:30'],
            'transaction_id' => ['required', 'string', 'max:120', 'unique:bookings,transaction_id'],
        ]);

        $customer = Client::query()->where('vendor_id', $vendorId)->find($validated['customerId']);
        $employee = Employee::query()->where('vendor_id', $vendorId)->find($validated['employeeId']);

        if (!$customer || !$employee) {
            return $this->notFoundResponse('Selected customer or employee not found for this vendor.');
        }

        $booking = Booking::query()->create([
            'vendor_id' => $vendorId,
            'customer_id' => $validated['customerId'],
            'employee_id' => $validated['employeeId'],
            'category' => $validated['category'],
            'location_id' => $validated['locationId'],
            'booking_date' => $validated['bookingDate'],
            'booking_time' => $validated['bookingTime'],
            'client_name' => $validated['clientName'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'],
            'service_address' => $validated['serviceAddress'],
            'amount' => $validated['amount'],
            'payment_status' => strtoupper($validated['payment_status']),
            'payment_method' => strtoupper($validated['payment_method']),
            'transaction_id' => $validated['transaction_id'],
        ]);

        return $this->successResponse([
            'id' => $booking->id,
            'transaction_id' => $booking->transaction_id,
            'payment_status' => $booking->payment_status,
        ], 'Booking confirmed successfully.', 201);
    }
}
