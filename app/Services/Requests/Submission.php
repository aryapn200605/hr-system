<?php

namespace App\Services\Requests;

use App\Enums\RequestStatus;
use App\Models\User;
use App\Policies\RequestPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The steps leave and overtime requests share: permission, rate limit, the requester lock with the
 * approval path check, and cancellation.
 *
 * Lock order, for every request change now and in Phase 10: the requester's user row first, then
 * the request row. Locking the user row serializes every submission of one user, so overlap and
 * balance checks read a state no other submission can change meanwhile. A cancellation only frees
 * dates and balance, so it needs the request row alone.
 */
class Submission
{
    public function __construct(private RequestPolicy $policy, private ApprovalPath $path) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function authorizeAndThrottle(User $actor): void
    {
        if (! $this->policy->create($actor)) {
            throw new AuthorizationException;
        }

        $key = 'requests:submit:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, config('requests.submissions_per_minute'))) {
            throw ValidationException::withMessages(['request' => __('Too many requests were submitted. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ])]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Lock the requester's row and check, from the database, that the account is active and has an
     * approval path. Must be the first statement of the transaction, so later reads see every request
     * committed before the lock was granted.
     *
     * @throws ValidationException
     */
    public function lockRequester(User $actor): User
    {
        $user = User::query()->with('roles')->lockForUpdate()->findOrFail($actor->getKey());

        if (($problem = $this->path->problem($user)) !== null) {
            throw ValidationException::withMessages(['request' => $problem]);
        }

        return $user;
    }

    /**
     * Cancel one of the actor's own pending requests. Another user's request is a 404, so nothing
     * about it leaks. A request already cancelled or decided is refused with its status.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     *
     * @throws AuthorizationException|ValidationException
     */
    public function cancel(User $actor, string $model, mixed $id): Model
    {
        if (! $this->policy->create($actor)) {
            throw new AuthorizationException;
        }

        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        // The request row lock alone serializes cancels and, in Phase 10, decisions on this request.
        $request = $model::query()->where('user_id', $actor->getKey())->lockForUpdate()->findOrFail($id);

        if ($request->status !== RequestStatus::Pending) {
            throw ValidationException::withMessages(['request' => __('This request is no longer pending: it is :status.', [
                'status' => $request->status->label(),
            ])]);
        }

        $request->status = RequestStatus::Cancelled;
        $request->cancelled_at = now();
        $request->save();

        return $request;
    }

    /**
     * @return list<string>
     */
    public function reasonRules(): array
    {
        return ['required', 'string', 'min:10', 'max:1000'];
    }
}
