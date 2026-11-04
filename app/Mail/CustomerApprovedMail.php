<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CustomerApprovedMail extends Mailable
{
    public function __construct(public string $customerCode, public string $portalUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre compte client a été approuvé');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-approved');
    }
}
