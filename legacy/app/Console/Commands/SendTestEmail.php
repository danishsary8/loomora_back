<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class SendTestEmail extends Command
{
    protected $signature = 'daycraft:mail-test {email : Recipient address} {--force : Allow sending in production}';

    protected $description = 'Send one Daycraft delivery test through the configured mail provider';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Production send blocked. Re-run with --force after checking the recipient.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            $this->error('Provide a valid recipient email address.');

            return self::INVALID;
        }

        if (config('mail.default') !== 'resend') {
            $this->error('MAIL_MAILER must be set to resend. Current mailer: '.config('mail.default'));

            return self::FAILURE;
        }

        if (blank(config('services.resend.key'))) {
            $this->error('RESEND_API_KEY is empty. Add a real Resend API key and redeploy.');

            return self::FAILURE;
        }

        $from = (string) config('mail.from.address');
        if (Validator::make(['from' => $from], ['from' => ['required', 'email:rfc']])->fails()) {
            $this->error('MAIL_FROM_ADDRESS is missing or invalid.');

            return self::FAILURE;
        }

        try {
            Mail::raw('Daycraft email delivery is configured correctly.', function ($message) use ($email): void {
                $message->to($email)->subject('Daycraft email delivery test');
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Delivery failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Test email accepted by Resend. Check the recipient inbox and the Resend delivery log.');

        return self::SUCCESS;
    }
}
