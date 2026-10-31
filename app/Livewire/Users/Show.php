<?php

namespace App\Livewire\Users;

use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $userId;

    /**
     * Check the permission on every request, because route middleware does not run for Livewire updates.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * The ID stays a plain route parameter. Route model binding runs before the permission
     * middleware, and its 404 would tell any logged-in user which accounts exist.
     */
    public function mount(string $user): void
    {
        $this->userId = User::query()->findOrFail($user)->getKey();
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        $user = User::query()->with(['roles', 'department', 'supervisors:id,name'])->findOrFail($this->userId);

        return view('livewire.users.show', ['user' => $user])
            ->title($user->name)
            ->layoutData(['breadcrumbs' => [
                ['label' => 'Home', 'href' => route('dashboard')],
                ['label' => 'User Management', 'href' => route('users.index')],
                ['label' => $user->name],
            ]]);
    }
}
