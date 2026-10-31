<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use stdClass;

trait ReplaysLivewire
{
    /**
     * Take the snapshot a browser holds for a page, for replaying it as someone else.
     */
    protected function snapshotOf(User $viewer, string $url): string
    {
        $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"[^>]*wire:effects/', $html, $matches);

        return html_entity_decode($matches[1]);
    }

    /**
     * Send a request to the real update endpoint.
     *
     * @param  array<string, mixed>  $updates
     * @param  array<int, mixed>  $params
     */
    protected function callLivewire(string $snapshot, ?string $method, array $params = [], array $updates = []): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates ?: new stdClass,
                'calls' => $method ? [['method' => $method, 'params' => $params, 'metadata' => new stdClass]] : [],
            ]],
        ], ['X-Livewire' => '1']);
    }
}
