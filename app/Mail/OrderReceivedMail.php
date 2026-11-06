<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class OrderReceivedMail extends Mailable
{
    public function __construct(public string $number, public string $accountUrl, private string $deliveryId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Réception de votre demande de commande');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.order-received');
    }

    public function headers(): Headers
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return new Headers(messageId: 'notification.'.$this->deliveryId.'@'.$domain);
    }
}
