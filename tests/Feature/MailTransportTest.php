<?php

use Illuminate\Support\Facades\Mail;

it('builds the Brevo API transport from config', function () {
    config(['services.brevo.key' => 'test-key-not-real']);

    expect((string) Mail::mailer('brevo')->getSymfonyTransport())->toStartWith('brevo+api://');
});

it('uses the log mailer when MAIL_MAILER is not set', function () {
    expect(config('mail.default'))->toBeIn(['log', 'array']);
});
