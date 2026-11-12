<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class QuoteAvailableMail extends Mailable
{
    public function __construct(public string $number, public string $accountUrl, private string $deliveryId) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Un devis est disponible dans votre compte');
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(text: 'mail.quote-available');
    }

    public function headers(): Headers
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return new Headers(messageId: 'quote-notification.'.$this->deliveryId.'@'.$domain);
    }
}
