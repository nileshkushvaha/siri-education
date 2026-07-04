@props([
    'items' => [],
    'multiple' => false,
])

@php
    $groupId = 'accordion-'.Illuminate\Support\Str::uuid()->toString();
@endphp

<div
    x-data="{
        open: @js($multiple ? [] : null),
        toggle(id) {
            if (@js($multiple)) {
                this.open = this.open.includes(id) ? this.open.filter(item => item !== id) : [...this.open, id];
                return;
            }
            this.open = this.open === id ? null : id;
        },
        isOpen(id) {
            return @js($multiple) ? this.open.includes(id) : this.open === id;
        }
    }"
    {{ $attributes->merge(['class' => 'divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white dark:divide-white/10 dark:border-white/10 dark:bg-white/[0.04]']) }}
>
    @forelse($items as $key => $item)
        @php
            $label = is_array($item) ? ($item['label'] ?? $key) : $key;
            $content = is_array($item) ? ($item['content'] ?? $item) : $item;
            $itemKey = (string) $key;
            $buttonId = $groupId.'-'.$key.'-button';
            $panelId = $groupId.'-'.$key.'-panel';
        @endphp
        <section>
            <h3>
                <button
                    id="{{ $buttonId }}"
                    type="button"
                    class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left text-sm font-semibold text-slate-900 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-indigo-100 dark:text-white dark:hover:bg-white/5 dark:focus-visible:ring-indigo-400/20"
                    x-on:click="toggle(@js($itemKey))"
                    x-bind:aria-expanded="isOpen(@js($itemKey))"
                    aria-controls="{{ $panelId }}"
                >
                    <span>{{ $label }}</span>
                    <svg class="h-4 w-4 shrink-0 text-slate-500 transition dark:text-slate-400" x-bind:class="isOpen(@js($itemKey)) && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </h3>
            <div
                id="{{ $panelId }}"
                role="region"
                aria-labelledby="{{ $buttonId }}"
                x-show="isOpen(@js($itemKey))"
                x-collapse
                x-cloak
            >
                <div class="px-4 pb-4 pt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">
                    {!! $content !!}
                </div>
            </div>
        </section>
    @empty
        {{ $slot }}
    @endforelse
</div>
