<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(public User $user, string $token)
    {
        $email = urlencode($user->email);
        $this->resetUrl = rtrim(config('app.frontend_url'), '/')."/reset-password?token={$token}&email={$email}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your RE-Account password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.reset-password');
    }
}
