<?php

namespace Tests\Unit;

use App\Mail\CustomerApprovedMail;
use Tests\TestCase;

class CustomerApprovedMailTest extends TestCase
{
    public function test_plain_text_email_preserves_the_code_and_portal_link(): void
    {
        $mail = new CustomerApprovedMail('000-A&B/<legacy>', 'https://portal.example.com/?a=1&b=2');

        $mail->assertSeeInText('000-A&B/<legacy>');
        $mail->assertSeeInText('https://portal.example.com/?a=1&b=2');
        $this->assertSame('Votre compte client a été approuvé', $mail->envelope()->subject);
        $this->assertNull($mail->content()->html);
    }
}
