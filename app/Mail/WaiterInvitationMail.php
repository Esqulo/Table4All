<?php

namespace App\Mail;

use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaiterInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WaiterInvitation $invitation,
        public readonly User $restaurant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Convite para ser garçom — {$this->restaurant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.waiter-invitation',
            with: [
                'restaurantName' => $this->restaurant->name,
                'acceptUrl'      => route('waiter.invite.accept', $this->invitation->token),
            ],
        );
    }
}
