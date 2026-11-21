<?php

namespace App\Mail;

use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** An invitation to become a platform admin. */
class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $inviter,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.admin_invitation.subject', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin-invitation',
            with: [
                'inviterName' => $this->inviter->name,
                'acceptUrl' => route('admin.invitation.show', $this->token),
                'hours' => AdminInvitation::VALID_HOURS,
            ],
        );
    }
}
