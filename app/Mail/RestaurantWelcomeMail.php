<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when the admin creates a restaurant's account: a link to choose its password. */
class RestaurantWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $restaurant,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.restaurant_welcome.subject', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.restaurant-welcome',
            with: [
                'restaurantName' => $this->restaurant->name,
                'passwordUrl' => route('password.reset', ['token' => $this->token, 'email' => $this->restaurant->email]),
                'minutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}
