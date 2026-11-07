<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verifyUrl;

    public function __construct(public User $user, string $token)
    {
        $this->verifyUrl = rtrim(config('app.frontend_url'), '/')."/verify-email?token={$token}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your RE-Account email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.verify-email');
    }
}
