<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Message;
use App\Models\Customer;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends BaseController
{
    /**
     * Vendor sees list of all customers they've messaged with last message + unread count.
     */
    public function getConversations(): JsonResponse
    {
        $user = auth()->user();
        $vendorId = (int) ($user->vendor_id ?? 0);

        if (!$vendorId) {
            return $this->forbiddenResponse('User is not associated with any vendor.');
        }

        $search = request()->query('search');

        // Find customer IDs associated with this vendor's quotes, jobs, or matching client emails
        $clientEmails = \App\Models\Client::where('vendor_id', $vendorId)->whereNotNull('email')->pluck('email');
        $customerIdsFromJobs = \App\Models\Job::where('vendor_id', $vendorId)->whereNotNull('customer_id')->pluck('customer_id');
        $customerIdsFromQuotes = \App\Models\Quote::where('vendor_id', $vendorId)->whereNotNull('customer_id')->pluck('customer_id');

        $query = Customer::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
            // Filter search results to only show customers associated with this vendor
            $query->where(function ($q) use ($clientEmails, $customerIdsFromJobs, $customerIdsFromQuotes) {
                $q->whereIn('email', $clientEmails)
                  ->orWhereIn('id', $customerIdsFromJobs)
                  ->orWhereIn('id', $customerIdsFromQuotes);
            });
        } else {
            // Load all customers associated with this vendor
            $query->whereIn('email', $clientEmails)
                  ->orWhereIn('id', $customerIdsFromJobs)
                  ->orWhereIn('id', $customerIdsFromQuotes);
        }

        $customers = $query->get();

        $conversations = $customers->map(function ($customer) use ($vendorId) {
            $lastMessage = Message::where('vendor_id', $vendorId)
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->first();

            $unreadCount = Message::where('vendor_id', $vendorId)
                ->where('customer_id', $customer->id)
                ->where('sender_type', 'customer')
                ->where('is_read', false)
                ->count();

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => $customer->status,
                'last_message' => $lastMessage ? [
                    'body' => $lastMessage->body,
                    'sender_type' => $lastMessage->sender_type,
                    'created_at' => $lastMessage->created_at->toISOString(),
                ] : null,
                'unread_count' => $unreadCount,
            ];
        });

        // Sort conversations: those with messages first (by latest message DESC), then those without messages
        $sorted = $conversations->sort(function ($a, $b) {
            $aTime = $a['last_message'] ? $a['last_message']['created_at'] : '';
            $bTime = $b['last_message'] ? $b['last_message']['created_at'] : '';
            
            if ($aTime && $bTime) {
                return strcmp($bTime, $aTime);
            }
            if ($aTime) return -1;
            if ($bTime) return 1;
            
            // If neither has messages, sort alphabetically by name
            return strcmp($a['name'], $b['name']);
        })->values();

        return $this->successResponse($sorted, 'Conversations retrieved successfully.');
    }

    /**
     * Fetch full chat history between logged-in vendor and a customer.
     */
    public function getMessages($customerId): JsonResponse
    {
        $user = auth()->user();
        $vendorId = (int) ($user->vendor_id ?? 0);

        if (!$vendorId) {
            return $this->forbiddenResponse('User is not associated with any vendor.');
        }

        $messages = Message::where('vendor_id', $vendorId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->successResponse($messages, 'Messages retrieved successfully.');
    }

    /**
     * Send a message (vendor or customer).
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'body' => 'required|string',
            'customer_id' => 'required_without:vendor_id|integer',
            'vendor_id' => 'required_without:customer_id|integer',
        ]);

        $customerData = request()->attributes->get('customer');
        if ($customerData) {
            $customerId = (int) $customerData['id'];
            $vendorId = (int) $request->input('vendor_id');
            $senderType = 'customer';
        } else {
            $user = auth()->user();
            $vendorId = (int) ($user->vendor_id ?? 0);
            if (!$vendorId) {
                return $this->forbiddenResponse('User is not associated with any vendor.');
            }
            $customerId = (int) $request->input('customer_id');
            $senderType = 'vendor';
        }

        $message = Message::create([
            'vendor_id' => $vendorId,
            'customer_id' => $customerId,
            'sender_type' => $senderType,
            'body' => $request->input('body'),
            'is_read' => false,
        ]);

        // Trigger Laravel Broadcasting event
        try {
            broadcast(new \App\Events\MessageSent($message))->toOthers();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Broadcasting failed: ' . $e->getMessage());
        }

        return $this->createdResponse($message, 'Message sent successfully.');
    }

    /**
     * Mark all messages in a conversation as read.
     */
    public function markAsRead($customerId): JsonResponse
    {
        $customerData = request()->attributes->get('customer');
        if ($customerData) {
            $customerId = (int) $customerData['id'];
            $vendorId = (int) request()->input('vendor_id');
            $affected = Message::where('vendor_id', $vendorId)
                ->where('customer_id', $customerId)
                ->where('sender_type', 'vendor')
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
        } else {
            $user = auth()->user();
            $vendorId = (int) ($user->vendor_id ?? 0);
            if (!$vendorId) {
                return $this->forbiddenResponse('User is not associated with any vendor.');
            }
            $affected = Message::where('vendor_id', $vendorId)
                ->where('customer_id', $customerId)
                ->where('sender_type', 'customer')
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
        }

        return $this->successResponse(['affected' => $affected], 'Conversation marked as read successfully.');
    }

    /**
     * Customer sees their conversation with their vendor.
     */
    public function getCustomerConversations(): JsonResponse
    {
        $customerData = request()->attributes->get('customer');
        if (!$customerData) {
            return $this->unauthorizedResponse('Not authenticated as customer.');
        }

        $customerId = (int) $customerData['id'];

        // Find vendor from messages
        $vendorId = Message::where('customer_id', $customerId)->latest('id')->value('vendor_id');

        if (!$vendorId) {
            // Find vendor from jobs, quotes or invoices
            $vendorId = \App\Models\Job::where('customer_id', $customerId)->latest('id')->value('vendor_id')
                ?: \App\Models\Quote::where('customer_id', $customerId)->latest('id')->value('vendor_id')
                ?: \App\Models\Invoice::where('customer_id', $customerId)->latest('id')->value('vendor_id')
                ?: Vendor::first()?->id;
        }

        $vendor = Vendor::find($vendorId);
        if (!$vendor) {
            return $this->successResponse([
                'vendor' => null,
                'messages' => [],
            ], 'No vendor associated with this customer.');
        }

        $messages = Message::where('vendor_id', $vendorId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->successResponse([
            'vendor' => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
                'full_name' => $vendor->full_name,
                'email' => $vendor->email,
            ],
            'messages' => $messages,
        ], 'Customer conversation retrieved successfully.');
    }

    /**
     * Returns total unread count for badge.
     */
    public function getUnreadCount(): JsonResponse
    {
        $customerData = request()->attributes->get('customer');
        if ($customerData) {
            $customerId = (int) $customerData['id'];
            $count = Message::where('customer_id', $customerId)
                ->where('sender_type', 'vendor')
                ->where('is_read', false)
                ->count();
        } else {
            $user = auth()->user();
            $vendorId = (int) ($user->vendor_id ?? 0);
            if (!$vendorId) {
                return $this->forbiddenResponse('User is not associated with any vendor.');
            }
            $count = Message::where('vendor_id', $vendorId)
                ->where('sender_type', 'customer')
                ->where('is_read', false)
                ->count();
        }

        return $this->successResponse(['count' => $count], 'Unread count retrieved successfully.');
    }

    /**
     * Unified Broadcast authentication endpoint.
     */
    public function broadcastAuth(Request $request): JsonResponse
    {
        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (!preg_match('/^private-chat\.vendor\.(\d+)\.customer\.(\d+)$/', $channelName, $matches)) {
            return $this->forbiddenResponse('Invalid channel format.');
        }

        $channelVendorId = (int) $matches[1];
        $channelCustomerId = (int) $matches[2];

        // Manually attempt to authenticate as Vendor or Customer via JWT
        $user = null;
        $customerId = null;

        try {
            $token = \Tymon\JWTAuth\Facades\JWTAuth::getToken();
            if ($token) {
                $payload = \Tymon\JWTAuth\Facades\JWTAuth::getPayload($token);
                $scope = $payload->get('scope');

                if ($scope === 'customer') {
                    $customerId = (int) $payload->get('sub');
                    $customer = Customer::find($customerId);
                    if (!$customer || $customer->status !== 'active') {
                        return $this->unauthorizedResponse('Inactive or invalid customer account.');
                    }
                } else {
                    $user = \Tymon\JWTAuth\Facades\JWTAuth::authenticate($token);
                }
            }
        } catch (\Exception $e) {
            return $this->unauthorizedResponse('Token authentication failed.');
        }

        if ($customerId) {
            if ($customerId !== $channelCustomerId) {
                return $this->forbiddenResponse('Unauthorized channel access for this customer.');
            }
        } elseif ($user) {
            $vendorId = (int) ($user->vendor_id ?? 0);
            if (!$vendorId || $vendorId !== $channelVendorId) {
                return $this->forbiddenResponse('Unauthorized channel access for this vendor.');
            }
        } else {
            return $this->unauthorizedResponse('Unauthenticated.');
        }

        $pusher = new \Pusher\Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        $auth = $pusher->authorizeChannel($channelName, $socketId);

        return response()->json(json_decode($auth, true));
    }
}
