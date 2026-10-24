<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class SendTestEmailCommandTest extends TestCase
{
    public function test_it_refuses_to_send_without_a_resend_api_key(): void
    {
        config()->set('mail.default', 'resend');
        config()->set('services.resend.key', null);

        $this->artisan('daycraft:mail-test', ['email' => 'owner@example.com'])
            ->expectsOutputToContain('RESEND_API_KEY is empty')
            ->assertFailed();
    }

    public function test_it_sends_through_the_configured_mailer(): void
    {
        Mail::fake();
        config()->set('mail.default', 'resend');
        config()->set('services.resend.key', 're_test_key');
        config()->set('mail.from.address', 'hello@daycraft.example');

        $this->artisan('daycraft:mail-test', ['email' => 'owner@example.com'])
            ->expectsOutputToContain('Test email accepted by Resend')
            ->assertSuccessful();
    }
}
