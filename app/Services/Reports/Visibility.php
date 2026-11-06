<?php

namespace App\Services\Reports;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
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
     * Whether the actor may see this user's requests: the same rule as apply(), for one user. Like
     * apply(), it is used behind viewHistory (seesAnything()).
     */
    public function seesUser(User $actor, int $userId): bool
    {
        return $this->apply(User::query()->whereKey($userId), $actor, 'users.id')->exists();
    }

    /**
     * The employee filter's search: at most $limit users this actor may see whose name or username contains
     * the term, by name. Nobody for an actor who sees only themself, so the list never offers others.
     *
     * @return list<array{value: string, label: string}>
     */
    public function search(User $actor, string $term, int $limit): array
    {
        if (! $this->seesOthers($actor)) {
            return [];
        }

        // mb_substr() also replaces invalid UTF-8, which would otherwise break the query.
        $term = trim(mb_substr($term, 0, 100));
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        return $this->apply(User::query(), $actor, 'users.id')
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', $pattern)->orWhere('username', 'like', $pattern)))
            ->orderBy('name')->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->getKey(), 'label' => $user->name])
            ->all();
    }

    /**
     * The names of the chosen users this actor may see, by ID, for the chips of the employee filter. IDs the
     * actor may not see get no name.
     *
     * @param  list<int>  $ids
     * @return array<string, string>
     */
    public function names(User $actor, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->apply(User::query()->whereKey($ids), $actor, 'users.id')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])
            ->all();
    }
}
