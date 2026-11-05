<?php

namespace App\Services\Requests;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Mail\RequestDecidedMail;
use App\Mail\RequestMail;
use App\Mail\RequestSubmittedMail;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\MailDelivery;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Request emails (Phase 13): to the users who may decide a new request, and to the requester once it is
 * decided. No email for a cancellation, and no opt-out.
 *
 * Called only after the business transaction committed, synchronously like the account emails. Nothing
 * here throws: a failed delivery never undoes or fails the request or the decision. Each failure is
 * logged with class names only (MailDelivery, or below when something else breaks) and audited per
 * recipient, and the number of failures is returned so the page can say so instead of claiming success.
 *
 * Links use APP_URL, not the host of the current request.
 */
class RequestNotifications
{
    public const SUBMITTED = 'submitted';

    public const DECIDED = 'decided';

    /**
     * Failed deliveries per request during this request lifecycle, for the page that triggered them.
     * The service is bound as scoped, so this never outlives one HTTP request or job.
     *
     * @var array<string, int>
     */
    private array $failures = [];

    public function __construct(private Deciders $deciders) {}

    /**
     * How many emails about this request failed in the current lifecycle.
     */
    public function failures(LeaveRequest|OvertimeRequest $record): int
    {
        return $this->failures[$record->routeType().':'.$record->getKey()] ?? 0;
    }

    /**
     * Email everyone who may decide this new request now. Returns how many deliveries failed.
     */
    public function submitted(LeaveRequest|OvertimeRequest $record, User $requester): int
    {
        return $this->safely(self::SUBMITTED, $record, $requester, function () use ($record, $requester) {
            $failed = 0;

            foreach ($this->deciders->of($requester) as $decider) {
                $mail = new RequestSubmittedMail($record, $decider->name, $this->link('approvals.show', $record), $requester->name);
                $failed += $this->deliver(self::SUBMITTED, $record, $requester, $decider, $mail);
            }

            return $failed;
        });
    }

    /**
     * Email the requester the outcome of a decision. A requester who is no longer active gets nothing.
     * Returns how many deliveries failed (0 or 1).
     */
    public function decided(LeaveRequest|OvertimeRequest $record, User $decider): int
    {
        return $this->safely(self::DECIDED, $record, $decider, function () use ($record, $decider) {
            $requester = User::query()->find($record->user_id);

            if ($requester === null || $requester->status !== UserStatus::Active) {
                return 0;
            }

            $mail = new RequestDecidedMail($record, $requester->name, $this->link('requests.show', $record));

            return $this->deliver(self::DECIDED, $record, $decider, $requester, $mail);
        });
    }

    private function deliver(string $kind, LeaveRequest|OvertimeRequest $record, User $actor, User $recipient, RequestMail $mail): int
    {
        if (MailDelivery::send($recipient->email, $mail, $recipient->preferredLocale())) {
            return 0;
        }

        $this->audit($kind, $record, $actor, $recipient->getKey());

        return 1;
    }

    /**
     * @param  callable(): int  $send
     */
    private function safely(string $kind, LeaveRequest|OvertimeRequest $record, User $actor, callable $send): int
    {
        $key = $record->routeType().':'.$record->getKey();

        try {
            return $this->failures[$key] = $send();
        } catch (Throwable $exception) {
            // Only the class: a message can quote the email or the query.
            Log::error('Request notification failed.', ['notification' => $kind, 'exception' => $exception::class]);

            try {
                $this->audit($kind, $record, $actor, null);
            } catch (Throwable $auditFailure) {
                Log::error('Request notification failure could not be audited.', ['exception' => $auditFailure::class]);
            }

            return $this->failures[$key] = 1;
        }
    }

    private function audit(string $kind, LeaveRequest|OvertimeRequest $record, User $actor, ?int $recipientId): void
    {
        AuditLog::record(AuditEvent::RequestNotificationFailed, $actor, $record, [
            'notification' => $kind,
            'recipient_id' => $recipientId,
        ]);
    }

    private function link(string $route, LeaveRequest|OvertimeRequest $record): string
    {
        return rtrim((string) config('app.url'), '/').route($route, ['type' => $record->routeType(), 'id' => $record->getKey()], false);
    }
}
