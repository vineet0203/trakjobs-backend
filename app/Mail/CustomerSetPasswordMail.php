<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerSetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public Customer $customer;
    public string $setupUrl;

    public function __construct(Customer $customer, string $setupUrl)
    {
        $this->customer = $customer;
        $this->setupUrl = $setupUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Set your customer account password - ' . config('app.name', 'TrakJobs'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer_set_password',
        );
    }
}