<?php

namespace App\Mail;

use App\Models\AssignedDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingFormMail extends Mailable
{
    use Queueable, SerializesModels;

    public AssignedDocument $assignment;
    public string $formUrl;

    public function __construct(AssignedDocument $assignment)
    {
        $this->assignment = $assignment;
        $this->formUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://trakjobs.com'))
            . '/fill-form/' . $assignment->token;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action Required: ' . $this->assignment->template->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding.form-assignment',
        );
    }
}
