{{--
    A first-class search section for the admin people lists, sitting above
    the table as its header.

    These lists carry no filter panel: search is the only way to narrow
    them, so it gets stated space and an explicit list of what it reaches,
    rather than the small box tucked into the toolbar's right edge. That
    default box is hidden by a rule in the admin theme keyed on this
    component's own class, so there is exactly one place to type.

    Bound to `tableSearch`, the same Livewire property Filament's own
    search field writes to — this changes where you type, never how the
    searching works.
--}}
@props([
    'heading' => 'Find a person',
    'placeholder' => 'Search…',
    'fields' => [],
])

<div class="fi-user-search-bar border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-white/10">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0 flex-1">
            <label
                for="{{ $this->getId() }}.user-search"
                class="mb-1.5 block text-sm font-semibold text-gray-950 dark:text-white"
            >
                {{ $heading }}
            </label>

            <x-filament::input.wrapper
                inline-prefix
                :prefix-icon="\Filament\Support\Icons\Heroicon::MagnifyingGlass"
                wire:target="tableSearch"
            >
                <x-filament::input
                    type="search"
                    inline-prefix
                    autocomplete="off"
                    maxlength="1000"
                    :id="$this->getId() . '.user-search'"
                    :placeholder="$placeholder"
                    wire:model.live.debounce.400ms="tableSearch"
                    x-on:keyup="if ($event.key === 'Enter') { $wire.$refresh() }"
                />
            </x-filament::input.wrapper>
        </div>

        <div x-cloak x-show="$wire.tableSearch" class="shrink-0 sm:self-end sm:pb-1">
            <x-filament::button
                color="gray"
                size="sm"
                icon="heroicon-m-x-mark"
                wire:click="$set('tableSearch', '')"
            >
                Clear search
            </x-filament::button>
        </div>
    </div>

    @if ($fields !== [])
        <div class="mt-3 flex flex-wrap items-center gap-1.5">
            <span class="text-xs text-gray-500 dark:text-gray-400">Searches</span>

            @foreach ($fields as $field)
                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    {{ $field }}
                </span>
            @endforeach
        </div>
    @endif
</div>
