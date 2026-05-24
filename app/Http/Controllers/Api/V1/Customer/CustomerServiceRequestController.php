<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Api\V1\Quote\QuoteCollection;
use App\Http\Resources\Api\V1\Quote\QuoteResource;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerServiceRequestController extends BaseController
{
    public function index(): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer();
        $clientData = \DB::table('clients')->where('email', $customer->email)->first();

        if (!$clientData) {
            return $this->successResponse(
                [],
                'Customer has no associated client profile.'
            );
        }

        $quotes = Quote::query()
            ->with(['items'])
            ->where('client_id', $clientData->id)
            ->whereNotNull('customer_id') // It's from a customer
            ->latest('id')
            ->get(); // use get() or paginate, let's use get() since frontend might not pass page but we can paginate(15) if we want
            // I'll return an array directly if it's get, or use QuoteCollection for paginate. Let's use get() to match some existing structures or paginate

        return response()->json([
            'success' => true,
            'message' => 'Service requests retrieved successfully.',
            'data' => QuoteResource::collection($quotes),
            'timestamp' => now()->toIso8601String(),
            'code' => 200,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer();
        $clientData = \DB::table('clients')->where('email', $customer->email)->first();

        if (!$clientData) {
            return $this->notFoundResponse('Service request not found.');
        }

        $quote = Quote::query()
            ->with(['items', 'reminders'])
            ->where('id', $id)
            ->where('client_id', $clientData->id)
            ->first();

        if (!$quote) {
            return $this->notFoundResponse('Service request not found.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Service request retrieved successfully.',
            'data' => (new QuoteResource($quote))->toArray(request()),
            'timestamp' => now()->toIso8601String(),
            'code' => 200,
        ]);
    }

    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:accepted,rejected',
        ]);

        $customer = $this->getAuthenticatedCustomer();
        $clientData = \DB::table('clients')->where('email', $customer->email)->first();

        if (!$clientData) {
            return $this->notFoundResponse('Service request not found.');
        }

        $quote = Quote::query()
            ->where('id', $id)
            ->where('client_id', $clientData->id)
            ->first();

        if (!$quote) {
            return $this->notFoundResponse('Service request not found.');
        }

        $action = strtolower($validated['action']);
        
        // For the service provider, accepting a "Service Request" means we approve it
        $quote->approval_status = $action === 'accepted' ? 'accepted' : 'rejected';
        $quote->status = $action === 'accepted' ? 'approved' : 'rejected';
        $quote->approval_date = now();
        $quote->approval_action_date = now();
        $quote->save();

        return response()->json([
            'success' => true,
            'message' => 'Service request status updated successfully.',
            'data' => [
                'id' => $quote->id,
                'approval_status' => $quote->approval_status,
                'status' => $quote->status,
            ],
            'timestamp' => now()->toIso8601String(),
            'code' => 200,
        ]);
    }

    private function getAuthenticatedCustomer(): Customer
    {
        $customerData = request()->attributes->get('customer');
        return Customer::findOrFail((int) $customerData['id']);
    }
}
