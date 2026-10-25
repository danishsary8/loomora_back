<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeedbackReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Feedback $feedback) {}

    public function envelope(): Envelope
    {
        $user = $this->feedback->user;

        return new Envelope(
            subject: __('account.feedback_mail_subject'),
            replyTo: $user ? [new Address($user->email, $user->name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.feedback-text');
    }
}
