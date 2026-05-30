<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuoteUpdateService
{
    /**
     * Update a quote
     */
    public function update(Quote $quote, array $data, int $updatedBy): Quote
    {
        if (!$quote->canBeEdited()) {
            throw new \Exception('Quote cannot be edited in its current status.');
        }

        DB::beginTransaction();

        try {
            // Fields that can be directly updated
            $fillableFields = [
                'title',
                'client_id',
                'equity_status',
                'quote_due_date',
                'currency',
                'discount',
                'is_tax_applicable',
                'tax_percentage',
                'deposit_required',
                'deposit_type',
                'deposit_amount',
                'approval_status',
                'client_signature',
                'approval_date',
                'approval_action_date',
                'can_convert_to_job',
                'notes',
                'status',
                'expires_at'
            ];

            $updateData = [];
            foreach ($fillableFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (array_key_exists('is_tax_applicable', $updateData)) {
                $isTaxApplicable = (bool) $updateData['is_tax_applicable'];
                $updateData['tax_percentage'] = $isTaxApplicable
                    ? (int) ($updateData['tax_percentage'] ?? $quote->tax_percentage ?? 0)
                    : 0;
            }

            // Handle status updates
            if (isset($data['status'])) {
                $updateData = array_merge($updateData, $this->handleStatusUpdate($quote, $data['status']));
            }

            // Add updated_by
            $updateData['updated_by'] = $updatedBy;

            // Only update if we have data
            if (!empty($updateData)) {
                $quote->update($updateData);
            }

            // Update items if provided - check both 'items' and 'line_items'
            if (isset($data['items'])) {
                $this->updateQuoteItems($quote, $data['items']);
                // Recalculate totals after items update
                $quote->calculateTotals();
            } elseif (isset($data['line_items'])) {
                $this->updateQuoteItems($quote, $data['line_items']);
                $quote->calculateTotals();
            }

            // Update reminders if provided
            if (isset($data['reminders'])) {
                $this->updateQuoteReminders($quote, $data['reminders'], $updatedBy);
            }

            // Auto-promote status to pending and reset approval fields if in draft/pending/rejected
            $customer = \App\Models\Customer::where('email', $quote->client_email)->first();
            $quote->update([
                'status' => 'pending',
                'sent_at' => now(),
                'approval_status' => 'pending',
                'approval_date' => null,
                'approval_action_date' => null,
                'customer_signature' => null,
                'customer_id' => $customer ? $customer->id : $quote->customer_id,
            ]);

            DB::commit();

            // Send notification/email to the customer
            $this->notifyCustomerOnUpdate($quote);

            Log::info('Quote updated successfully', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'updated_by' => $updatedBy,
            ]);

            return $quote->fresh(['items', 'reminders', 'updater']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update quote', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'updated_by' => $updatedBy,
            ]);
            throw $e;
        }
    }

    /**
     * Handle status update logic
     */
    private function handleStatusUpdate(Quote $quote, string $status): array
    {
        $updateData = [];

        switch ($status) {
            case 'approved':
                $updateData['approval_date'] = now();
                $updateData['approval_status'] = 'accepted';
                break;
            case 'rejected':
                $updateData['approval_date'] = now();
                $updateData['approval_status'] = 'rejected';
                break;
            case 'sent':
                $updateData['sent_at'] = now();
                break;
            case 'expired':
                $updateData['expires_at'] = now();
                break;
        }

        return $updateData;
    }


    /**
     * Update quote items
     */
    private function updateQuoteItems(Quote $quote, array $items): void
    {
        QuoteItem::where('quote_id', $quote->id)->delete();

        $sortOrder = 0;

        foreach ($items as $item) {
            if (isset($item['_delete']) && $item['_delete'] === true) {
                continue;
            }

            $subtotal = $item['quantity'] * $item['unit_price'];
            $taxAmount = $subtotal * (($item['tax_rate'] ?? 0) / 100);

            QuoteItem::create([
                'quote_id' => $quote->id,
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
        }
    }

    /**
     * Update quote reminders
     */
    private function updateQuoteReminders(Quote $quote, array $reminders, int $updatedBy): void
    {
        $existingReminderIds = [];

        foreach ($reminders as $reminder) {
            if (isset($reminder['id']) && $reminder['id'] && !str_starts_with($reminder['id'], 'temp')) {
                // Update existing reminder
                $quoteReminder = QuoteReminder::where('quote_id', $quote->id)
                    ->where('id', $reminder['id'])
                    ->first();

                if ($quoteReminder && !($reminder['_delete'] ?? false)) {
                    $quoteReminder->update([
                        'scheduled_at' => $reminder['follow_up_schedule'],
                        'reminder_type' => $reminder['reminder_type'],
                        'status' => $reminder['reminder_status'] ?? 'scheduled',
                        'updated_by' => $updatedBy,
                    ]);

                    $existingReminderIds[] = $quoteReminder->id;
                } elseif ($quoteReminder && ($reminder['_delete'] ?? false)) {
                    $quoteReminder->delete();
                }
            } elseif (!($reminder['_delete'] ?? false)) {
                // Create new reminder
                $quoteReminder = new QuoteReminder([
                    'quote_id' => $quote->id,
                    'scheduled_at' => $reminder['follow_up_schedule'],
                    'reminder_type' => $reminder['reminder_type'],
                    'status' => $reminder['reminder_status'] ?? 'scheduled',
                    'created_by' => $updatedBy,
                    'updated_by' => $updatedBy,
                ]);

                $quoteReminder->save();
                $existingReminderIds[] = $quoteReminder->id;
            }
        }

        // Delete reminders not in the updated list
        QuoteReminder::where('quote_id', $quote->id)
            ->whereNotIn('id', $existingReminderIds)
            ->delete();
    }

    /**
     * Update follow-up status
     */
    public function updateFollowUpStatus(Quote $quote, string $status, int $updatedBy): Quote
    {
        $quote->update([
            'follow_up_status' => $status,
            'updated_by' => $updatedBy,
        ]);

        Log::info('Quote follow-up status updated', [
            'quote_id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'status' => $status,
            'updated_by' => $updatedBy,
        ]);

        return $quote->fresh();
    }

    /**
     * Send email and database notification to customer on quote update
     */
    private function notifyCustomerOnUpdate(Quote $quote): void
    {
        try {
            $fresh = $quote->fresh();
            if ($fresh && $fresh->client_email) {
                $customer = \App\Models\Customer::where('email', $fresh->client_email)->first();
                if ($customer) {
                    \App\Models\CustomerNotification::create([
                        'customer_id' => $customer->id,
                        'type'        => 'quote_updated',
                        'title'       => 'Quotation Updated',
                        'message'     => 'Quote #' . $fresh->quote_number . ' - ' . $fresh->title . ' has been updated and is ready for your review.',
                        'data'        => ['quote_id' => $fresh->id],
                    ]);

                    // Send email notification
                    $loginUrl = rtrim((string) config('app.customer_frontend_url', 'https://customer.trakjobs.com'), '/') . '/login';
                    \Illuminate\Support\Facades\Mail::send('emails.quote_notification', [
                        'title' => 'Quotation Updated',
                        'greeting' => 'Hello ' . ($customer->name ?: 'Customer') . ',',
                        'name' => $customer->name ?: 'Customer',
                        'body' => 'A quotation has been revised/updated by the vendor. Please review the updated details below and log in to the Customer Panel to accept or reject it.',
                        'quoteNumber' => $fresh->quote_number,
                        'quoteTitle' => $fresh->title,
                        'totalAmount' => '$' . number_format((float) $fresh->total_amount, 2),
                        'loginUrl' => $loginUrl,
                    ], function ($message) use ($customer, $fresh) {
                        $message->to($customer->email)
                            ->subject('Updated Quotation #' . $fresh->quote_number . ' - ' . config('app.name', 'TrackJobs'));
                    });

                    Log::info('Quote update email notification sent to customer', [
                        'quote_id' => $fresh->id,
                        'email' => $customer->email,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify customer on quote update: ' . $e->getMessage());
        }
    }
}
