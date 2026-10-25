<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.reset.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reset-password', with: [
            'minutes' => config('auth.passwords.users.expire'),
        ]);
    }
}
