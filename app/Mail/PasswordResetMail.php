<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * Never queued: the job payload would store the plain token. See App\Support\MailDelivery.
 */
class PasswordResetMail extends Mailable
{
    public string $url;

    /**
     * Create a new message instance. The link holds no email address, so none reaches URLs or access logs.
     */
    public function __construct(
        public string $name,
        #[SensitiveParameter] string $token,
    ) {
        $this->url = route('password.reset.start', $token);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Reset your :app password', ['app' => config('app.name')]));
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset', with: [
            'expiresInMinutes' => config('auth.passwords.users.expire'),
        ]);
    }
}
