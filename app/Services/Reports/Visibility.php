<?php

namespace App\Services\Reports;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whose requests and balances a user may see in history, recap and exports. The one rule:
 *
 * - requests.view-all: everyone;
 * - requests.submit (needed to open the pages at all without view-all): their own;
 * - requests.decide: also the users linked to them as supervisor now (links exist only to Approvers).
 *
 * The links are read as they are now, so a removed link hides that user's requests at once. No role
 * name is read: the permissions come from App\Enums\RoleName::permissions().
 */
class Visibility
{
    public function seesEveryone(User $actor): bool
    {
        return $actor->hasPermissionTo(PermissionName::RequestsViewAll);
    }

    /**
     * Whether the user may see anyone but themself, so the pages offer employee and department filters.
     */
    public function seesOthers(User $actor): bool
    {
        return $this->seesEveryone($actor) || $actor->hasPermissionTo(PermissionName::RequestsDecide);
    }

    /**
     * Whether the user may open history and recap at all.
     */
    public function seesAnything(User $actor): bool
    {
        return $this->seesEveryone($actor) || $actor->hasPermissionTo(PermissionName::RequestsSubmit);
    }

    /**
     * Limit a query to the rows of users this actor may see. $column holds the user ID.
     */
    public function apply(Builder $query, User $actor, string $column): Builder
    {
        if ($this->seesEveryone($actor)) {
            return $query;
        }

        // Their own rows always: history and recap open only with requests.submit or requests.view-all.
        return $query->where(function (Builder $query) use ($actor, $column) {
            $query->where($column, $actor->getKey());

            if ($actor->hasPermissionTo(PermissionName::RequestsDecide)) {
                $query->orWhereIn($column, DB::table('supervisor_user')->select('subordinate_id')->where('supervisor_id', $actor->getKey()));
            }
        });
    }

    /**
     * The users this actor may see, by name, for the employee filter.
     *
     * @return Collection<int, User>
     */
    public function users(User $actor): Collection
    {
        return $this->apply(User::query(), $actor, 'users.id')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }
}
