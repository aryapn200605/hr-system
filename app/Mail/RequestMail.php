<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use Illuminate\Mail\Mailable;

/**
 * The shared part of the request notifications (Phase 13): the type, the dates or hours and a link,
 * never the reason, the decision note, the rejection reason or the evidence.
 *
 * The request is protected, so the view receives only what details() hands it. The details are built
 * when the email is rendered, inside the recipient's language (MailDelivery::send()), so dates and
 * labels follow that language. Sent synchronously after the business transaction committed.
 */
abstract class RequestMail extends Mailable
{
    public function __construct(
        protected LeaveRequest|OvertimeRequest $record,
        public string $recipientName,
        public string $url,
    ) {}

    /**
     * @return array{type: string, category: ?string, when: string, length: string}
     */
    protected function details(): array
    {
        $record = $this->record;

        if ($record instanceof LeaveRequest) {
            $starts = $record->starts_on->translatedFormat('j M Y');
            $ends = $record->ends_on->translatedFormat('j M Y');

            return [
                'type' => __('Leave'),
                'category' => $record->category->label(),
                'when' => $starts === $ends ? $starts : $starts.' – '.$ends,
                'length' => __(':count working days', ['count' => $record->working_days]),
            ];
        }

        $sameDay = $record->start_at->isSameDay($record->end_at);

        return [
            'type' => __('Overtime'),
            'category' => null,
            'when' => $record->start_at->translatedFormat('j M Y, H:i').' – '
                .$record->end_at->translatedFormat($sameDay ? 'H:i T' : 'j M Y, H:i T'),
            'length' => __(':count hours', ['count' => $record->hours]),
        ];
    }
}
