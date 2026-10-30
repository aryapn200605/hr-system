<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * Never queued: the job payload would store the plain token. See App\Support\MailDelivery.
 */
class InvitationMail extends Mailable
{
    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $name,
        #[SensitiveParameter] string $token,
        public bool $resent = false,
    ) {
        $this->url = route('activation.start', $token);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->resent
                ? __('Your new invitation to :app', ['app' => config('app.name')])
                : __('You are invited to :app', ['app' => config('app.name')]),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'expiresInHours' => (int) ceil(config('auth.invitations.expire') / 60),
        ]);
    }
}
