<?php

namespace App\Mail;

use App\Models\AdmissionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the Table4All team about a new admission request.
 * Queued so a slow or failing mail server never blocks the visitor;
 * the request itself is already stored and visible in the admin panel.
 */
class AdmissionRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly AdmissionRequest $admissionRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->admissionRequest->contact_email)],
            subject: "Nova solicitação de acesso — {$this->admissionRequest->business_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admission-request',
            with: [
                'request' => $this->admissionRequest,
                'panelUrl' => route('admin.admission-requests.index'),
            ],
        );
    }
}
