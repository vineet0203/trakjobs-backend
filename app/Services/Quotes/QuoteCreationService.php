<?php

namespace App\Services\Quotes;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CustomerNotification;

class QuoteCreationService
{
    /**
     * Create a new quote with items
     */
    public function create(array $data, int $createdBy): Quote
    {
        DB::beginTransaction();

        try {
            // Log incoming data for debugging
            Log::info('QuoteCreationService create method called', [
                'data_keys' => array_keys($data),
                'has_items' => isset($data['items']),
                'has_line_items' => isset($data['line_items']),
                'created_by' => $createdBy
            ]);

            // Fetch client details from database
            $client = Client::findOrFail($data['client_id']);

            // Find matching Customer
            $customer = \App\Models\Customer::where('email', $client->email)->first();

            // Generate quote number
            $quoteNumber = Quote::generateQuoteNumber();

            // Calculate deposit amount if percentage
            $depositAmount = $this->calculateDepositAmount($data);

            // Create quote with client details from database
            $quote = Quote::create([
                'quote_number' => $quoteNumber,
                'title' => $data['title'],
                'client_id' => $data['client_id'],
                'customer_id' => $customer ? $customer->id : null,
                'client_name' => $this->getClientDisplayName($client),
                'client_email' => $client->email,
                'equity_status' => $data['equity_status'] ?? 'not_applicable',
                'quote_due_date' => $data['quote_due_date'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'subtotal' => 0,
                'discount' => $data['discount'] ?? 0,
                'is_tax_applicable' => (bool) ($data['is_tax_applicable'] ?? false),
                'tax_percentage' => (bool) ($data['is_tax_applicable'] ?? false) ? (int) ($data['tax_percentage'] ?? 0) : 0,
                'total_amount' => 0,
                'deposit_required' => $data['deposit_required'] ?? false,
                'deposit_type' => ($data['deposit_required'] ?? false) ? ($data['deposit_type'] ?? null) : null,
                'deposit_amount' => $depositAmount,
                'approval_status' => $data['approval_status'] ?? 'pending',
                'client_signature' => $data['client_signature'] ?? null,
                'approval_date' => $data['approval_date'] ?? null,
                'approval_action_date' => $data['approval_action_date'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'can_convert_to_job' => $data['can_convert_to_job'] ?? true,
                'notes' => $data['notes'] ?? null,
                'vendor_id' => auth()->user()->vendor_id ?? null,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
                'expires_at' => $data['expires_at'] ?? now()->addDays(30),
            ]);

            // Create quote items - Check for both 'items' and 'line_items'
            $items = $data['items'] ?? $data['line_items'] ?? null;

            if (!$items) {
                throw new \Exception('No items provided for quote creation');
            }

            $this->createQuoteItems($quote, $items);

            // Calculate totals
            $quote->calculateTotals();

            // Create reminders if any
            if (!empty($data['reminders'])) {
                $this->createReminders($quote, $data['reminders'], $createdBy);
            }

            DB::commit();

            Log::info('Quote created successfully', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'client_id' => $quote->client_id,
                'items_count' => count($items),
                'created_by' => $createdBy,
            ]);

            return $quote->fresh(['items', 'reminders']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create quote', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => array_keys($data),
                'client_id' => $data['client_id'] ?? null,
                'created_by' => $createdBy,
            ]);
            throw $e;
        }
    }

    /**
     * Get client display name based on client type
     */
    private function getClientDisplayName(Client $client): string
    {
        if ($client->client_type === 'commercial') {
            return $client->business_name ?? 'Unnamed Business';
        } else {
            $firstName = $client->first_name ?? '';
            $lastName = $client->last_name ?? '';
            return trim($firstName . ' ' . $lastName) ?: 'Unnamed Client';
        }
    }

    /**
     * Calculate deposit amount based on type
     */
    private function calculateDepositAmount(array $data): ?float
    {
        if (!($data['deposit_required'] ?? false)) {
            return null;
        }

        // For percentage deposits, we'll calculate after items are created
        // For now, return the raw amount (will be recalculated in calculateTotals)
        return $data['deposit_amount'] ?? null;
    }

    /**
     * Create quote items
     */
    private function createQuoteItems(Quote $quote, array $items): void
    {
        $sortOrder = 0;

        foreach ($items as $item) {
            // Skip items marked for deletion
            if (isset($item['_delete']) && $item['_delete'] === true) {
                continue;
            }

            $subtotal = $item['quantity'] * $item['unit_price'];
            $taxAmount = $subtotal * (($item['tax_rate'] ?? 0) / 100);

            $quoteItem = new QuoteItem([
                'item_name' => $item['item_name'],
                'description' => $item['description'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate' => $item['tax_rate'] ?? 0,
                'tax_amount' => $taxAmount,
                'item_total' => $subtotal + $taxAmount,
                'sort_order' => $sortOrder++,
                'package_id' => $item['package_id'] ?? null,
            ]);

            $quote->items()->save($quoteItem);
        }

        Log::info('Quote items created', [
            'quote_id' => $quote->id,
            'items_count' => count($items),
        ]);
    }

    /**
     * Create reminders
     */
    private function createReminders(Quote $quote, array $reminders, int $createdBy): void
    {
        foreach ($reminders as $reminder) {
            // Skip reminders marked for deletion
            if (isset($reminder['_delete']) && $reminder['_delete'] === true) {
                continue;
            }

            $quote->reminders()->create([
                'scheduled_at' => $reminder['follow_up_schedule'],
                'reminder_type' => $reminder['reminder_type'],
                'status' => $reminder['reminder_status'] ?? 'scheduled',
                'created_by' => $createdBy,
            ]);
        }

        Log::info('Reminders created for quote', [
            'quote_id' => $quote->id,
            'reminders_count' => count($reminders),
        ]);
    }

    /**
     * Send quote to client (status changes to 'pending' for approval)
     */
    public function sendQuote(Quote $quote, int $sentBy): Quote
    {
        if (!$quote->canBeSent()) {
            throw new \Exception('Quote cannot be sent. Check if it has items and is in draft status.');
        }

        $quote->update([
            'status' => 'pending',
            'sent_at' => now(),
            'updated_by' => $sentBy,
        ]);

        Log::info('Quote sent to client (pending approval)', [
            'quote_id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'client_email' => $quote->client_email,
            'sent_by' => $sentBy,
        ]);

        // Notify customer on quote send — match by email since clients and customers are separate tables
        try {
            $fresh = $quote->fresh();
            if ($fresh && $fresh->client_email) {
                $customer = \App\Models\Customer::where('email', $fresh->client_email)->first();
                if ($customer) {
                    if (!$fresh->customer_id) {
                        $quote->update(['customer_id' => $customer->id]);
                        $fresh = $quote->fresh();
                    }

                    CustomerNotification::create([
                        'customer_id' => $customer->id,
                        'type'        => 'quote_sent',
                        'title'       => 'New Quote Received',
                        'message'     => 'Quote #' . $fresh->quote_number . ' - ' . $fresh->title . ' has been sent for your approval.',
                        'data'        => ['quote_id' => $fresh->id],
                    ]);

                    // Send email notification
                    $loginUrl = rtrim((string) config('app.customer_frontend_url', 'https://customer.trakjobs.com'), '/') . '/login';
                    \Illuminate\Support\Facades\Mail::send('emails.quote_notification', [
                        'title' => 'New Quotation Received',
                        'greeting' => 'Hello ' . ($customer->name ?: 'Customer') . ',',
                        'name' => $customer->name ?: 'Customer',
                        'body' => 'A new quotation has been prepared for you. Please review the details below and log in to the Customer Panel to accept or reject it.',
                        'quoteNumber' => $fresh->quote_number,
                        'quoteTitle' => $fresh->title,
                        'totalAmount' => '$' . number_format((float) $fresh->total_amount, 2),
                        'loginUrl' => $loginUrl,
                    ], function ($message) use ($customer, $fresh) {
                        $message->to($customer->email)
                            ->subject('New Quotation #' . $fresh->quote_number . ' - ' . config('app.name', 'TrackJobs'));
                    });

                    Log::info('Quote email notification sent to customer', [
                        'quote_id' => $fresh->id,
                        'email' => $customer->email,
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Customer notification failed: ' . $e->getMessage());
        }

        return $quote->fresh();
    }

    /**
     * Validate if client already has similar quote
     */
    public function validateDuplicateQuote(string $clientEmail, string $title): bool
    {
        $vendorId = auth()->user()->vendor_id;

        if (!$vendorId) {
            return false;
        }

        return Quote::where('vendor_id', $vendorId)
            ->where('client_email', $clientEmail)
            ->where('title', 'like', '%' . $title . '%')
            ->whereIn('status', ['draft', 'pending'])
            ->exists();
    }

    /**
     * Schedule follow-up reminders
     */
    public function scheduleFollowUps(Quote $quote, array $reminderData, int $userId): Quote
    {
        $reminder = $quote->reminders()->create([
            'scheduled_at' => $reminderData['follow_up_schedule'],
            'reminder_type' => $reminderData['reminder_type'],
            'status' => 'scheduled',
            'created_by' => $userId,
        ]);

        Log::info('Follow-up reminder scheduled', [
            'quote_id' => $quote->id,
            'reminder_id' => $reminder->id,
            'scheduled_at' => $reminder->scheduled_at,
        ]);

        return $quote->fresh('reminders');
    }

    /**
     * Cancel a scheduled reminder
     */
    public function cancelReminder(Quote $quote, int $reminderId, int $userId): Quote
    {
        $reminder = $quote->reminders()->findOrFail($reminderId);

        $reminder->update([
            'status' => 'cancelled',
        ]);

        Log::info('Reminder cancelled', [
            'quote_id' => $quote->id,
            'reminder_id' => $reminderId,
            'cancelled_by' => $userId,
        ]);

        return $quote->fresh('reminders');
    }
}
