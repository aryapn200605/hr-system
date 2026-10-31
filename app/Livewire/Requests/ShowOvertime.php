<?php

namespace App\Livewire\Requests;

use App\Models\OvertimeRequest;
use Livewire\Component;

class ShowOvertime extends Component
{
    use ShowsRequest;

    protected function model(): string
    {
        return OvertimeRequest::class;
    }

    /**
     * Render the component.
     */
    public function render(): mixed
    {
        return view('livewire.requests.show-overtime', [
            'request' => $this->findOwn($this->requestId),
        ])->title('Overtime request')->layoutData(['breadcrumbs' => $this->breadcrumbs('Overtime request')]);
    }
}
