<?php

use Illuminate\Support\Facades\Mail;

it('builds the Brevo API transport from config', function () {
    config(['services.brevo.key' => 'test-key-not-real']);

    expect((string) Mail::mailer('brevo')->getSymfonyTransport())->toStartWith('brevo+api://');
});
