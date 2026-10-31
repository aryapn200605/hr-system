<div class="space-y-6">
    <x-ui.page-header title="Move subordinates" description="Move everyone, or the people you choose, from one supervisor to another. Nothing moves unless all of it can." />

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="move" class="space-y-4 py-2">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.select label="Current supervisor" id="move-from" wire:model.live="fromId">
                    <option value="">Choose a supervisor</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source->id }}">{{ $source->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="New supervisor" id="move-to" wire:model="toId">
                    <option value="">Choose an active Approver</option>
                    @foreach ($targets as $target)
                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.select label="Who to move" id="move-scope" wire:model.live="scope">
                <option value="all">All subordinates</option>
                <option value="selected">Chosen subordinates</option>
            </x-ui.select>

            @if ($scope === 'selected')
                <fieldset>
                    <legend class="text-sm font-medium text-ink-soft">Subordinates</legend>

                    @forelse ($subordinates as $subordinate)
                        <label class="mt-2 flex items-center gap-2 text-sm wrap-anywhere text-ink" wire:key="subordinate-{{ $subordinate->id }}">
                            <input type="checkbox" class="size-4 shrink-0 accent-accent" value="{{ $subordinate->id }}" wire:model="selected">
                            {{ $subordinate->name }}
                        </label>
                    @empty
                        <p class="mt-2 text-sm text-muted">Choose the current supervisor first.</p>
                    @endforelse
                </fieldset>
            @endif

            <x-ui.button type="submit" loading="move">Move</x-ui.button>
        </form>
    </x-ui.card>
</div>
