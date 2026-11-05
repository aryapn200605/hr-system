<?php

namespace App\Mail;

use App\Enums\RequestStatus;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the requester once a request is approved or rejected. Says the outcome, never the decision note
 * or the rejection reason: those stay behind the login.
 */
class RequestDecidedMail extends RequestMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved()
            ? __('Your request was approved')
            : __('Your request was rejected'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.request-decided', with: $this->details() + [
            'approved' => $this->approved(),
            'outcome' => $this->record->status->label(),
        ]);
    }

    private function approved(): bool
    {
        return $this->record->status === RequestStatus::Approved;
    }
}
