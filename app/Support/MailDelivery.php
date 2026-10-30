<?php

namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends account emails and reports whether the transport accepted them, for callers that must not claim success.
 *
 * Emails carry tokens, so they are never queued: the job payload would store the plain token.
 */
class MailDelivery
{
    /**
     * Mailers that deliver nothing. The log mailer also writes the whole email to the application log.
     */
    private const LOCAL_MAILERS = ['log', 'array'];

    /**
     * Send an email. Logs only the classes involved, because a transport error can quote the message.
     */
    public static function send(string $address, Mailable $mail): bool
    {
        if (app()->isProduction() && in_array(config('mail.default'), self::LOCAL_MAILERS, true)) {
            Log::error('Mail delivery refused: a local mailer is configured in production.', [
                'mailable' => $mail::class,
            ]);

            return false;
        }

        try {
            Mail::to($address)->send($mail);

            return true;
        } catch (Throwable $exception) {
            Log::error('Mail delivery failed.', [
                'mailable' => $mail::class,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
