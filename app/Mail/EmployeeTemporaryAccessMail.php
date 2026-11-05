<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

class EmployeeTemporaryAccessMail extends Mailable
{
    public function __construct(#[SensitiveParameter] private string $secret, private string $portalUrl, private string $expiresAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre accès temporaire FTP');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.employee-temporary-access', with: ['temporaryPassword' => $this->secret, 'portalUrl' => $this->portalUrl, 'expiresAt' => $this->expiresAt]);
    }
}
