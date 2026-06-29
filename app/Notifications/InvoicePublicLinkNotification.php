<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePublicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries;
    public $timeout;

    public function __construct(
        protected Invoice $invoice,
        protected string $publicUrl,
        protected ?string $expiresAt = null,
    ) {
        $this->tries = config('notifications.tries', 3);
        $this->timeout = config('notifications.timeout', 30);
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return [
            'mail' => config('notifications.queues.emails', 'emails'),
        ];
    }

    public function backoff(): array
    {
        return config('notifications.backoff', [10, 30, 60]);
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invoice ' . $this->invoice->invoice_number . ' is ready')
            ->view('emails.invoice.public-link', [
                'invoice' => $this->invoice,
                'publicUrl' => $this->publicUrl,
                'expiresAt' => $this->expiresAt,
                'appName' => config('app.name', 'TrakJobs'),
                'currentYear' => date('Y'),
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'invoice_public_link',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'public_url' => $this->publicUrl,
            'expires_at' => $this->expiresAt,
        ];
    }
}
