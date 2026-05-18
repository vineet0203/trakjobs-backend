<?php

namespace App\Helpers;

use App\Models\Notification;
use App\Models\CustomerNotification;
use Illuminate\Support\Facades\Log;

class NotificationHelper
{
    // ── Vendor/Employee Notification ─────────────────────────────────────────

    public static function notifyUser(int $userId, string $type, string $title, string $message, array $data = []): void
    {
        try {
            Notification::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'message' => $message,
                'data'    => $data,
                'is_read' => false,
            ]);
        } catch (\Exception $e) {
            Log::error('NotificationHelper::notifyUser failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Customer Notification ────────────────────────────────────────────────

    public static function notifyCustomer(int $customerId, string $type, string $title, string $message, array $data = []): void
    {
        try {
            CustomerNotification::create([
                'customer_id' => $customerId,
                'type'        => $type,
                'title'       => $title,
                'message'     => $message,
                'data'        => $data,
                'is_read'     => false,
            ]);
        } catch (\Exception $e) {
            Log::error('NotificationHelper::notifyCustomer failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Invoice Events ───────────────────────────────────────────────────────

    public static function invoiceCreated($invoice, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'invoice_created', 'Invoice Created', "Invoice #{$invoice->invoice_number} has been created.", ['invoice_id' => $invoice->id]);
        if ($invoice->customer_id) {
            self::notifyCustomer($invoice->customer_id, 'invoice_created', 'New Invoice', "You have a new invoice #{$invoice->invoice_number}.", ['invoice_id' => $invoice->id]);
        }
    }

    public static function invoiceSent($invoice, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'invoice_sent', 'Invoice Sent', "Invoice #{$invoice->invoice_number} has been sent to customer.", ['invoice_id' => $invoice->id]);
        if ($invoice->customer_id) {
            self::notifyCustomer($invoice->customer_id, 'invoice_sent', 'Invoice Received', "Invoice #{$invoice->invoice_number} has been sent to you.", ['invoice_id' => $invoice->id]);
        }
    }

    public static function invoicePaid($invoice, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'invoice_paid', 'Invoice Paid', "Invoice #{$invoice->invoice_number} has been marked as paid.", ['invoice_id' => $invoice->id]);
        if ($invoice->customer_id) {
            self::notifyCustomer($invoice->customer_id, 'invoice_paid', 'Payment Confirmed', "Your payment for invoice #{$invoice->invoice_number} is confirmed.", ['invoice_id' => $invoice->id]);
        }
    }

    // ── Quote Events ─────────────────────────────────────────────────────────

    public static function quoteCreated($quote, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'quote_created', 'Quote Created', "Quote #{$quote->quote_number} has been created.", ['quote_id' => $quote->id]);
        if ($quote->customer_id) {
            self::notifyCustomer($quote->customer_id, 'quote_created', 'New Quote', "You have received a new quote #{$quote->quote_number}.", ['quote_id' => $quote->id]);
        }
    }

    public static function quoteSent($quote, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'quote_sent', 'Quote Sent', "Quote #{$quote->quote_number} sent to customer.", ['quote_id' => $quote->id]);
        if ($quote->customer_id) {
            self::notifyCustomer($quote->customer_id, 'quote_sent', 'Quote Received', "Quote #{$quote->quote_number} has been sent to you for review.", ['quote_id' => $quote->id]);
        }
    }

    public static function quoteApproved($quote, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'quote_approved', 'Quote Approved', "Quote #{$quote->quote_number} has been approved by customer.", ['quote_id' => $quote->id]);
        if ($quote->customer_id) {
            self::notifyCustomer($quote->customer_id, 'quote_approved', 'Quote Approved', "You have approved quote #{$quote->quote_number}.", ['quote_id' => $quote->id]);
        }
    }

    public static function quoteRejected($quote, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'quote_rejected', 'Quote Rejected', "Quote #{$quote->quote_number} has been rejected.", ['quote_id' => $quote->id]);
    }

    // ── Job Events ───────────────────────────────────────────────────────────

    public static function jobCreated($job, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'job_created', 'Job Created', "Job #{$job->job_number} - {$job->title} has been created.", ['job_id' => $job->id]);
    }

    public static function jobAssigned($job, $employee, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'job_assigned', 'Job Assigned', "Job #{$job->job_number} assigned to {$employee->name}.", ['job_id' => $job->id, 'employee_id' => $employee->id]);
        if ($employee->user_id) {
            self::notifyUser($employee->user_id, 'job_assigned', 'New Job Assigned', "You have been assigned to job #{$job->job_number} - {$job->title}.", ['job_id' => $job->id]);
        }
    }

    public static function jobStatusUpdated($job, string $newStatus, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'job_status_updated', 'Job Status Updated', "Job #{$job->job_number} status changed to {$newStatus}.", ['job_id' => $job->id, 'status' => $newStatus]);
        if ($job->customer_id) {
            self::notifyCustomer($job->customer_id, 'job_status_updated', 'Job Update', "Your job #{$job->job_number} status is now {$newStatus}.", ['job_id' => $job->id]);
        }
    }

    // ── Booking Events ───────────────────────────────────────────────────────

    public static function bookingCreated($booking, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'booking_created', 'New Booking', "New booking received from {$booking->customer_name}.", ['booking_id' => $booking->id]);
        if ($booking->customer_id) {
            self::notifyCustomer($booking->customer_id, 'booking_created', 'Booking Confirmed', "Your booking has been received and is under review.", ['booking_id' => $booking->id]);
        }
    }

    // ── Schedule Events ──────────────────────────────────────────────────────

    public static function scheduleCreated($schedule, $employee, int $vendorUserId): void
    {
        self::notifyUser($vendorUserId, 'schedule_created', 'Schedule Created', "New schedule created for {$schedule->start_datetime}.", ['schedule_id' => $schedule->id]);
        if ($employee && $employee->user_id) {
            self::notifyUser($employee->user_id, 'schedule_created', 'New Schedule', "You have a new schedule on {$schedule->start_datetime}.", ['schedule_id' => $schedule->id]);
        }
    }
}
