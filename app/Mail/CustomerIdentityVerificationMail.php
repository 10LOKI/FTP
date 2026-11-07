<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class CustomerIdentityVerificationMail extends Mailable
{
    public function __construct(public string $verificationUrl, private string $deliveryId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmez votre nouvelle adresse e-mail');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-identity-verification');
    }

    public function headers(): Headers
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return new Headers(messageId: 'notification.'.$this->deliveryId.'@'.$domain);
    }
}
