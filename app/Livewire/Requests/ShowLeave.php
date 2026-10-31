<?php

namespace App\Livewire\Requests;

use App\Models\LeaveRequest;
use Livewire\Component;

class ShowLeave extends Component
{
    use ShowsRequest;

    protected function model(): string
    {
        return LeaveRequest::class;
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.requests.show-leave', [
            'request' => $this->findOwn($this->requestId)->load('category'),
        ])->title('Leave request')->layoutData(['breadcrumbs' => $this->breadcrumbs('Leave request')]);
    }
}
