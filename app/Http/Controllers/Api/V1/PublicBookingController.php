<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublicBookingController extends BaseController
{
    /**
     * Handle public booking submission from the landing page.
     * Matches the requested service to all suitable clients and generates a quote request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'location' => ['required', 'string', 'max:255'],
            'service_category' => ['required', 'string', 'max:100'],
            'service_sub_category' => ['required', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
            'time' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            DB::beginTransaction();

            // 1. Get or create the Customer (End-user making the booking)
            $customer = Customer::firstOrCreate(
                ['email' => $validated['email']],
                [
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'status' => 'active',
                ]
            );

            // 2. Find all Clients (subcontractors/service providers) that match the requested service
            $matchingClients = Client::where('service_category', $validated['service_category'])
                ->where('service_sub_category', $validated['service_sub_category'])
                ->get();

            if ($matchingClients->isEmpty()) {
                DB::rollBack();
                return $this->errorResponse('No service providers are currently available for this service in your area.', 404);
            }

            $quotesCreated = 0;

            // 3. Generate a Quote/Request for each matched client
            foreach ($matchingClients as $client) {
                // Determine vendor from client
                $vendorId = $client->vendor_id;

                // Create the quote
                $quote = Quote::create([
                    'quote_number' => Quote::generateQuoteNumber(),
                    'title' => 'New Lead: ' . str_replace('_', ' ', $validated['service_sub_category']),
                    'client_id' => $client->id,
                    'customer_id' => $customer->id,
                    'vendor_id' => $vendorId,
                    'client_name' => $customer->name,
                    'client_email' => $customer->email,
                    'status' => 'pending', // Pending provider response
                    'notes' => "Location: {$validated['location']}\nDate: {$validated['date']}\nTime: {$validated['time']}\nNotes: {$validated['notes']}",
                    'subtotal' => 0,
                    'total_amount' => 0,
                ]);

                // Get the main user_id of the vendor to send the notification to
                $vendorUserId = $client->vendor->user_id ?? null;

                if ($vendorUserId) {
                    // Create a notification for the Vendor/Client
                    Notification::create([
                        'user_id' => $vendorUserId,
                        'title' => 'New Service Request',
                        'message' => "A new booking request for {$validated['service_sub_category']} has been automatically routed to your client {$client->business_name}.",
                        'type' => 'booking_request',
                        'is_read' => false,
                        'data' => ['url' => "/quotes/{$quote->id}"],
                    ]);
                }

                $quotesCreated++;
            }

            DB::commit();

            return $this->successResponse([
                'matched_providers' => $quotesCreated,
                'customer_id' => $customer->id
            ], 'Booking submitted successfully. Matching service providers have been notified.', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Public Booking Error: ' . $e->getMessage());
            return $this->errorResponse('An error occurred while processing your booking.', 500);
        }
    }
}
