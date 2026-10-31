<?php

namespace App\Livewire\Supervisors;

use App\Enums\PermissionName;
use App\Exceptions\AccountProtectionException;
use App\Models\User;
use App\Support\SupervisorRelations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Move extends Component
{
    private const SCOPES = ['all', 'selected'];

    public string $fromId = '';

    public string $toId = '';

    public string $scope = 'all';

    /**
     * @var list<string>
     */
    public array $selected = [];

    /**
     * A warning that must stay on screen, unlike a toast. Written by the server only.
     */
    #[Locked]
    public string $notice = '';

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize(PermissionName::SupervisorsManage->value);
    }

    /**
     * Refuse anything the form cannot send: plain strings, and a flat list of them for the checkboxes.
     */
    public function updating(string $name, mixed $value): void
    {
        $isList = is_array($value) && array_filter($value, 'is_string') === $value;
        $isSelection = str_starts_with($name, 'selected');

        abort_unless(is_string($value) || ($isSelection && $isList), 422);
        abort_if(! $isSelection && str_contains($name, '.'), 422);
    }

    /**
     * A new source supervisor has other subordinates, so the old choices no longer apply.
     */
    public function updatedFromId(): void
    {
        $this->selected = [];
    }

    public function move(): void
    {
        $this->notice = '';

        $from = $this->findUser($this->fromId);
        $to = $this->findUser($this->toId);

        if (! $from) {
            $this->addError('fromId', 'Choose the current supervisor.');
        }

        if (! $to) {
            $this->addError('toId', 'Choose the new supervisor.');
        }

        if (! in_array($this->scope, self::SCOPES, true)) {
            $this->addError('scope', 'Choose who to move.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $moved = SupervisorRelations::move($from, $to, $this->employeeIds(), Auth::user());
        } catch (AccountProtectionException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        $this->reset(['fromId', 'toId', 'scope', 'selected']);
        $this->dispatch('toast', message: $moved.' moved to '.$to->name.'.', tone: 'success');
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $from = $this->findUser($this->fromId);

        return view('livewire.supervisors.move', [
            'sources' => User::query()->has('subordinates')->orderBy('name')->get(['id', 'name']),
            'targets' => User::query()->activeSupervisor()->orderBy('name')->get(['id', 'name']),
            'subordinates' => $from ? $from->subordinates()->orderBy('name')->get(['users.id', 'users.name']) : new Collection,
        ])->title('Move subordinates')->layoutData(['breadcrumbs' => [
            ['label' => 'Home', 'href' => route('dashboard')],
            ['label' => 'Move subordinates'],
        ]]);
    }

    /**
     * @return list<int>|null
     */
    private function employeeIds(): ?array
    {
        if ($this->scope === 'all') {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (mixed $id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            $this->selected,
        )));
    }

    private function findUser(string $id): ?User
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : User::query()->find($id);
    }
}
