<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To each user who may decide a new request. Names the requester, nothing about the reason or evidence.
 */
class RequestSubmittedMail extends RequestMail
{
    public function __construct(
        LeaveRequest|OvertimeRequest $record,
        string $recipientName,
        string $url,
        public string $requesterName,
    ) {
        parent::__construct($record, $recipientName, $url);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('A request from :name is waiting for your decision', ['name' => $this->requesterName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.request-submitted', with: $this->details());
    }
}
