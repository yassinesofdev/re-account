<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\OrgInvite;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $acceptUrl;

    public function __construct(public OrgInvite $invite, public Organization $organization, public User $inviter, string $token)
    {
        $this->acceptUrl = rtrim(config('app.frontend_url'), '/')."/invites/{$token}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to join {$this->organization->name} on RE-Account");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.invite');
    }
}
