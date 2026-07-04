@props([
    'tabs' => [],
    'active' => null,
])

@php
    $defaultActive = $active ?? array_key_first($tabs);
    $groupId = 'tabs-'.Illuminate\Support\Str::uuid()->toString();
@endphp

<div x-data="{ selected: @js($defaultActive) }" {{ $attributes }}>
    @if(count($tabs) > 0)
        <div class="border-b border-slate-200 dark:border-white/10">
            <div class="-mb-px flex gap-1 overflow-x-auto" role="tablist" aria-orientation="horizontal">
                @foreach($tabs as $key => $tab)
                    @php
                        $label = is_array($tab) ? ($tab['label'] ?? $key) : $tab;
                        $tabId = $groupId.'-'.$key.'-tab';
                        $panelId = $groupId.'-'.$key.'-panel';
                    @endphp
                    <button
                        id="{{ $tabId }}"
                        type="button"
                        role="tab"
                        x-on:click="selected = @js($key)"
                        x-bind:aria-selected="selected === @js($key)"
                        aria-controls="{{ $panelId }}"
                        class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:focus-visible:ring-indigo-400/20"
                        x-bind:class="selected === @js($key) ? 'border-indigo-600 text-indigo-600 dark:border-indigo-300 dark:text-indigo-200' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach($tabs as $key => $tab)
            @php
                $content = is_array($tab) ? ($tab['content'] ?? null) : null;
                $tabId = $groupId.'-'.$key.'-tab';
                $panelId = $groupId.'-'.$key.'-panel';
            @endphp
            <div
                id="{{ $panelId }}"
                role="tabpanel"
                tabindex="0"
                aria-labelledby="{{ $tabId }}"
                x-show="selected === @js($key)"
                x-cloak
                class="py-4 text-slate-700 focus:outline-none dark:text-slate-300"
            >
                @if($content)
                    {!! $content !!}
                @endif
            </div>
        @endforeach
    @endif

    @if(trim((string) $slot) !== '')
        {{ $slot }}
    @endif
</div>
