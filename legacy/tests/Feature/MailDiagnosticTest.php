<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class MailDiagnosticTest extends TestCase
{
    public function test_diagnostic_is_hidden_without_the_correct_token(): void
    {
        config()->set('app.diagnostic_token', 'correct-token');

        $this->getJson('/api/v1/internal/mail-diagnostic?token=wrong-token')->assertNotFound();
    }

    public function test_diagnostic_returns_only_safe_configuration_values(): void
    {
        config()->set('app.diagnostic_token', 'correct-token');
        config()->set('mail.default', 'resend');
        config()->set('services.resend.key', 'secret-key-that-must-not-leak');
        config()->set('mail.from.address', 'hello@example.com');

        $response = $this->getJson('/api/v1/internal/mail-diagnostic?token=correct-token')
            ->assertOk()
            ->assertJsonPath('data.mailer', 'resend')
            ->assertJsonPath('data.resend_api_key_set', true)
            ->assertJsonPath('data.from_address', 'hello@example.com');

        $this->assertStringNotContainsString('secret-key-that-must-not-leak', $response->getContent());
    }

    public function test_mail_test_sends_when_configuration_and_token_are_valid(): void
    {
        Mail::fake();
        config()->set('app.diagnostic_token', 'correct-token');
        config()->set('mail.default', 'resend');
        config()->set('services.resend.key', 're_test_key');
        config()->set('mail.from.address', 'hello@example.com');

        $this->getJson('/api/v1/internal/mail-test?token=correct-token&email=owner%40example.com')
            ->assertOk()
            ->assertJsonPath('data.message', 'Test email accepted by Resend. Check the inbox and Resend delivery log.');
    }
}
