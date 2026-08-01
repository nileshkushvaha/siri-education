<div
    x-data="navigationMenuBuilder()"
    x-init="init()"
    class="fi-nav-builder">

    @once
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    @endonce

    {{-- ── Two-panel layout (30 / 70) ─────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6 items-start">

        {{-- ══════════════════════════════════════════════════════════════
             LEFT PANEL — Add Items  (~33%)
        ══════════════════════════════════════════════════════════════════ --}}
        <div class="lg:col-span-1 space-y-2">

            <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest px-1 mb-3">Add items</p>

            @foreach([
            [
            'key' => 'pages',
            'label' => 'Pages',
            'open' => true,
            'color' => '#3b82f6',
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'searchWire' => 'searchPages',
            'placeholder' => 'Search pages…',
            'items' => $this->pages,
            'addMethod' => 'addPage',
            'nameField' => 'title',
            'slugField' => 'slug',
            'hasStatus' => true,
            'hoverBg' => 'hover:bg-blue-50 dark:hover:bg-blue-900/20',
            'btnColor' => 'text-blue-600 dark:text-blue-400 border-blue-300 dark:border-blue-700 hover:bg-blue-50 dark:hover:bg-blue-900/30',
            'emptyMsg' => 'No pages found',
            ],
            [
            'key' => 'posts',
            'label' => 'Posts',
            'open' => false,
            'color' => '#22c55e',
            'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z',
            'searchWire' => 'searchPosts',
            'placeholder' => 'Search posts…',
            'items' => $this->posts,
            'addMethod' => 'addPost',
            'nameField' => 'title',
            'slugField' => 'slug',
            'hasStatus' => true,
            'hoverBg' => 'hover:bg-green-50 dark:hover:bg-green-900/20',
            'btnColor' => 'text-green-600 dark:text-green-400 border-green-300 dark:border-green-700 hover:bg-green-50 dark:hover:bg-green-900/30',
            'emptyMsg' => 'No posts found',
            ],
            [
            'key' => 'categories',
            'label' => 'Categories',
            'open' => false,
            'color' => '#f97316',
            'icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
            'searchWire' => 'searchCategories',
            'placeholder' => 'Search categories…',
            'items' => $this->categories,
            'addMethod' => 'addCategory',
            'nameField' => 'name',
            'slugField' => 'slug',
            'hasStatus' => false,
            'hoverBg' => 'hover:bg-orange-50 dark:hover:bg-orange-900/20',
            'btnColor' => 'text-orange-600 dark:text-orange-400 border-orange-300 dark:border-orange-700 hover:bg-orange-50 dark:hover:bg-orange-900/30',
            'emptyMsg' => 'No categories found',
            ],
            [
            'key' => 'tags',
            'label' => 'Tags',
            'open' => false,
            'color' => '#a855f7',
            'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A2 2 0 013 9.414V5a2 2 0 012-2z',
            'searchWire' => 'searchTags',
            'placeholder' => 'Search tags…',
            'items' => $this->tags,
            'addMethod' => 'addTag',
            'nameField' => 'name',
            'slugField' => 'slug',
            'hasStatus' => false,
            'hoverBg' => 'hover:bg-violet-50 dark:hover:bg-violet-900/20',
            'btnColor' => 'text-violet-600 dark:text-violet-400 border-violet-300 dark:border-violet-700 hover:bg-violet-50 dark:hover:bg-violet-900/30',
            'emptyMsg' => 'No tags found',
            ],
            ] as $panel)
            <div
                x-data="{ open: {{ $panel['open'] ? 'true' : 'false' }} }"
                class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden bg-white dark:bg-gray-800 shadow-sm">
                {{-- Accordion header --}}
                <button type="button" @click="open = !open"
                    class="w-full flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-750 transition-colors text-left">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md flex-shrink-0" style="background-color: {{ $panel['color'] }}1a">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" style="color: {{ $panel['color'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $panel['icon'] }}" />
                        </svg>
                    </span>
                    <span class="flex-1 font-semibold text-[13px]">{{ $panel['label'] }}</span>
                    @if($panel['items']->isNotEmpty())
                    <span class="text-[10px] font-bold tabular-nums px-1.5 py-0.5 rounded-full text-white" style="background-color: {{ $panel['color'] }}">
                        {{ $panel['items']->count() }}{{ $panel['items']->count() === 20 ? '+' : '' }}
                    </span>
                    @endif
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 transition-transform duration-200 flex-shrink-0" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                {{-- Accordion body --}}
                <div x-show="open" x-collapse class="border-t border-gray-100 dark:border-gray-700/60">
                    {{-- Search --}}
                    <div class="px-3 pt-2.5 pb-1.5">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-2 h-3.5 w-3.5 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                wire:model.live.debounce.300ms="{{ $panel['searchWire'] }}"
                                type="text"
                                placeholder="{{ $panel['placeholder'] }}"
                                class="w-full text-xs pl-8 pr-3 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700/70 text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:ring-1 focus:border-transparent"
                                style="--tw-ring-color: {{ $panel['color'] }}">
                        </div>
                    </div>

                    {{-- Item list --}}
                    <ul class="px-2 pb-2 space-y-0.5 max-h-52 overflow-y-auto">
                        @forelse($panel['items'] as $item)
                        <li class="group flex items-center gap-2 px-2 py-1.5 rounded-lg {{ $panel['hoverBg'] }} transition-colors cursor-default">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-gray-800 dark:text-gray-100 truncate leading-snug">{{ $item->{$panel['nameField']} }}</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate leading-tight font-mono">/{{ $item->{$panel['slugField']} }}</p>
                            </div>
                            @if($panel['hasStatus'] && isset($item->status))
                            @php
                            $sv = $item->status instanceof \BackedEnum ? $item->status->value : (string)$item->status;
                            $statusCls = match($sv) {
                            'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
                            'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
                            default => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
                            };
                            @endphp
                            <span class="shrink-0 hidden group-hover:inline-flex items-center px-1.5 py-0 rounded text-[10px] font-medium leading-5 {{ $statusCls }}">
                                {{ ucfirst($sv) }}
                            </span>
                            @endif
                            <button
                                type="button"
                                wire:click="{{ $panel['addMethod'] }}('{{ $item->id }}')"
                                wire:loading.attr="disabled"
                                class="shrink-0 h-6 px-2.5 text-[11px] font-bold border rounded-lg transition-all {{ $panel['btnColor'] }} disabled:opacity-50"
                                title="Add to menu">
                                + Add
                            </button>
                        </li>
                        @empty
                        <li class="flex flex-col items-center py-6 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-300 dark:text-gray-600 mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <p class="text-xs text-gray-400 italic">{{ $panel['emptyMsg'] }}</p>
                        </li>
                        @endforelse
                    </ul>
                </div>
            </div>
            @endforeach

            {{-- ── Custom Link ── --}}
            <div x-data="{ open: false }" class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden bg-white dark:bg-gray-800 shadow-sm">
                <button type="button" @click="open = !open"
                    class="w-full flex items-center gap-2.5 px-3.5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-750 transition-colors text-left">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md bg-gray-100 dark:bg-gray-700 flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                    </span>
                    <span class="flex-1 font-semibold text-[13px]">Custom Link</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 transition-transform duration-200 flex-shrink-0" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div x-show="open" x-collapse class="border-t border-gray-100 dark:border-gray-700/60">
                    <div class="p-3 space-y-2.5">

                        {{-- Type selector --}}
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Link type</label>
                            <select wire:model.live="customLinkType"
                                class="w-full text-xs px-2.5 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-1 focus:ring-primary-500">
                                <option value="url">🌐 URL</option>
                                <option value="route">🔗 Route name</option>
                                <option value="email">✉️ Email address</option>
                                <option value="phone">📞 Phone number</option>
                                <option value="anchor">⚓ Anchor (#)</option>
                            </select>
                        </div>

                        @php
                        $clLabelWire = match($customLinkType) {
                        'route' => 'routeLabel',
                        'email' => 'emailLabel',
                        'phone' => 'phoneLabel',
                        'anchor' => 'anchorLabel',
                        default => 'customUrlLabel',
                        };
                        $clValueWire = match($customLinkType) {
                        'route' => 'routeNameInput',
                        'email' => 'emailInput',
                        'phone' => 'phoneInput',
                        'anchor' => 'anchorInput',
                        default => 'customUrlInput',
                        };
                        $clPlaceholder = match($customLinkType) {
                        'route' => 'home, blog.index…',
                        'email' => 'hello@sirieducation.com',
                        'phone' => '+1 (555) 000-0000',
                        'anchor' => '#section-id',
                        default => 'https://…',
                        };
                        $clValueType = match($customLinkType) {
                        'email' => 'email',
                        'phone' => 'tel',
                        'url' => 'url',
                        default => 'text',
                        };
                        $clValueLabel = match($customLinkType) {
                        'route' => 'Route name',
                        'email' => 'Email address',
                        'phone' => 'Phone number',
                        'anchor' => 'Anchor ID',
                        default => 'URL',
                        };
                        @endphp

                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Label</label>
                            <input wire:model="{{ $clLabelWire }}" type="text" placeholder="Navigation label"
                                class="w-full text-xs px-2.5 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">{{ $clValueLabel }}</label>
                            <input wire:model="{{ $clValueWire }}" type="{{ $clValueType }}" placeholder="{{ $clPlaceholder }}"
                                class="w-full text-xs px-2.5 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary-500">
                        </div>

                        <button type="button" wire:click="addCustomLink" wire:loading.attr="disabled"
                            class="w-full py-2 text-xs font-bold bg-primary-600 text-white rounded-lg hover:bg-primary-700 active:scale-95 transition-all flex items-center justify-center gap-1.5 disabled:opacity-60">
                            <span wire:loading.remove wire:target="addCustomLink" class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                </svg>
                                Add to menu
                            </span>
                            <span wire:loading wire:target="addCustomLink" class="flex items-center gap-1.5">
                                <svg class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Adding…
                            </span>
                        </button>
                    </div>
                </div>
            </div>

        </div>{{-- /LEFT PANEL --}}


        {{-- ══════════════════════════════════════════════════════════════
             RIGHT PANEL — Menu Structure  (~67%)
        ══════════════════════════════════════════════════════════════════ --}}
        <div class="lg:col-span-2">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-3 px-1">
                <div class="flex items-center gap-2.5">
                    <p class="text-[11px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Menu structure</p>
                    @if(!empty($treeItems))
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400">
                        {{ count($treeItems) }} {{ Str::plural('item', count($treeItems)) }}
                    </span>
                    @endif

                    {{-- Reorder loader badge --}}
                    <span wire:loading wire:target="reorder" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                        <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Saving…
                    </span>
                    <span wire:loading.remove wire:target="reorder" class="hidden"></span>
                </div>
                @if(!empty($treeItems))
                <div class="flex items-center gap-1.5 text-[10px] text-gray-400 dark:text-gray-500 select-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                    </svg>
                    Drag to reorder &middot; Drop onto an item to nest
                </div>
                @endif
            </div>

            {{-- Empty state --}}
            @if(empty($treeItems))
            <div class="flex flex-col items-center justify-center py-20 px-6 bg-white dark:bg-gray-800 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-2xl text-center shadow-sm">
                <div class="relative mb-6">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-700/60 dark:to-gray-700/30 border border-gray-200 dark:border-gray-600 flex items-center justify-center shadow-inner">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-gray-300 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M4 6h16M4 10h16M4 14h10M4 18h6" />
                        </svg>
                    </div>
                    <span class="absolute -bottom-1 -right-1 h-6 w-6 rounded-full bg-primary-500 border-2 border-white dark:border-gray-800 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" />
                        </svg>
                    </span>
                </div>
                <p class="text-sm font-bold text-gray-700 dark:text-gray-200">This menu is empty</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 max-w-xs leading-relaxed">
                    Add pages, posts, categories, tags, or a custom link from the left panel.
                    Items can be nested by dragging one onto another.
                </p>
            </div>

            @else
            {{-- Tree container with loading overlay --}}
            <div class="relative">
                {{-- Loading overlay during reorder --}}
                <div
                    wire:loading wire:target="reorder"
                    class="absolute inset-0 z-10 bg-white/60 dark:bg-gray-800/60 backdrop-blur-[1px] rounded-2xl flex items-center justify-center">
                    <div class="flex items-center gap-2 px-4 py-2.5 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <svg class="animate-spin h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Saving order…</span>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-4 shadow-sm">
                    <ul
                        id="nav-tree-root"
                        data-sortable
                        data-sortable-root="true"
                        data-parent="null"
                        class="space-y-0">
                        @include('livewire.navigation.partials.tree-item', ['items' => $treeItems, 'depth' => 0])
                    </ul>
                </div>
            </div>
            <div class="mt-2.5 flex items-center justify-center gap-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <p class="text-[10px] text-gray-400 dark:text-gray-600">
                    Changes save automatically when you reorder
                </p>
            </div>
            @endif

        </div>{{-- /RIGHT PANEL --}}
    </div>{{-- /grid --}}


    {{-- ════════════════════════════════════════════════════════════════════
         EDIT SLIDE-OVER
    ════════════════════════════════════════════════════════════════════════ --}}
    <div
        x-show="$wire.showSlideOver"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-hidden"
        style="display: none;">
        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-gray-900/50 dark:bg-gray-950/70 backdrop-blur-sm" @click="$wire.cancelEdit()"></div>

        {{-- Panel --}}
        <div
            x-show="$wire.showSlideOver"
            x-data="{ activeTab: 'general' }"
            x-transition:enter="ease-out duration-250"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="absolute inset-y-0 right-0 w-full max-w-lg bg-white dark:bg-gray-800 shadow-2xl flex flex-col">
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0 bg-gray-50 dark:bg-gray-800/80">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">Edit Menu Item</h2>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Customize label, link behaviour &amp; visibility</p>
                    </div>
                </div>
                <button type="button" @click="$wire.cancelEdit()"
                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Tab nav --}}
            <div class="flex border-b border-gray-200 dark:border-gray-700 flex-shrink-0 bg-white dark:bg-gray-800 overflow-x-auto">
                @foreach([
                ['key' => 'general', 'label' => 'General', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
                ['key' => 'link', 'label' => 'Link', 'icon' => 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'],
                ['key' => 'visibility', 'label' => 'Visibility', 'icon' => 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'],
                ['key' => 'publishing', 'label' => 'Publishing', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
                ['key' => 'advanced', 'label' => 'Advanced', 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
                ] as $tab)
                <button
                    type="button"
                    @click="activeTab = '{{ $tab['key'] }}'"
                    :class="activeTab === '{{ $tab['key'] }}'
                        ? 'border-primary-500 text-primary-600 dark:text-primary-400 bg-primary-50/50 dark:bg-primary-900/10'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/30'"
                    class="flex items-center gap-1.5 py-3 px-3 text-[11px] font-bold border-b-2 transition-all whitespace-nowrap tracking-wide uppercase">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $tab['icon'] }}" />
                    </svg>
                    {{ $tab['label'] }}
                </button>
                @endforeach
            </div>

            {{-- Scrollable form --}}
            <div class="flex-1 overflow-y-auto">

                {{-- ═══ GENERAL ═══ --}}
                <div x-show="activeTab === 'general'" class="px-6 py-5 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">
                            Label <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="editForm.label" type="text" placeholder="Navigation label"
                            class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        @error('editForm.label')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400">The text shown in the navigation menu.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Icon</label>
                        <input wire:model="editForm.icon" type="text" placeholder="heroicon-o-home"
                            class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <p class="mt-1 text-xs text-gray-400">Heroicon name or custom CSS class. Leave blank for no icon.</p>
                    </div>

                    <div class="flex items-center justify-between p-4 rounded-xl bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600">
                        <div>
                            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Active</p>
                            <p class="text-xs text-gray-400 mt-0.5">Hidden from all visitors when disabled</p>
                        </div>
                        <button type="button" role="switch"
                            :aria-checked="$wire.editForm.is_active ? 'true' : 'false'"
                            @click="$wire.set('editForm.is_active', !$wire.editForm.is_active)"
                            :class="$wire.editForm.is_active ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-600'"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                            <span :class="$wire.editForm.is_active ? 'translate-x-6' : 'translate-x-1'"
                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                        </button>
                    </div>
                </div>

                {{-- ═══ LINK ═══ --}}
                <div x-show="activeTab === 'link'" class="px-6 py-5 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Open in</label>
                        <select wire:model="editForm.target"
                            class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="_self">Same window</option>
                            <option value="_blank">New tab</option>
                            <option value="_parent">Parent frame</option>
                            <option value="_top">Full window</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Rel attribute</label>
                        <input wire:model="editForm.rel" type="text" placeholder="noopener noreferrer"
                            class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <p class="mt-1 text-xs text-gray-400">Space-separated values e.g. <code class="font-mono">noopener noreferrer nofollow</code></p>
                    </div>

                    <div class="flex items-center justify-between p-4 rounded-xl bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600">
                        <div>
                            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Open in Modal</p>
                            <p class="text-xs text-gray-400 mt-0.5">Opens link in a modal dialog overlay</p>
                        </div>
                        <button type="button" role="switch"
                            :aria-checked="$wire.editForm.open_in_modal ? 'true' : 'false'"
                            @click="$wire.set('editForm.open_in_modal', !$wire.editForm.open_in_modal)"
                            :class="$wire.editForm.open_in_modal ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-600'"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                            <span :class="$wire.editForm.open_in_modal ? 'translate-x-6' : 'translate-x-1'"
                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                        </button>
                    </div>
                </div>

                {{-- ═══ VISIBILITY ═══ --}}
                <div x-show="activeTab === 'visibility'" class="px-6 py-5 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Show to</label>
                        <select wire:model.live="editForm.visibility"
                            class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="all">Everyone (public)</option>
                            <option value="guest">Guests only (logged-out)</option>
                            <option value="auth">Authenticated users</option>
                            <option value="roles">Users with specific roles</option>
                            <option value="permissions">Users with specific permissions</option>
                        </select>
                    </div>

                    @if(($editForm['visibility'] ?? '') === 'roles')
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Required Roles</label>
                        <div class="max-h-48 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($this->availableRoles as $role)
                            <label class="flex items-center gap-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 px-3 py-2.5">
                                <input type="checkbox" wire:model="editForm.required_role_ids" value="{{ $role->id }}"
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 h-3.5 w-3.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $role->name }}</span>
                            </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-gray-400">User must have at least one of the selected roles.</p>
                    </div>
                    @endif

                    @if(($editForm['visibility'] ?? '') === 'permissions')
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Required Permissions</label>
                        <div class="max-h-48 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($this->availablePermissions as $permission)
                            <label class="flex items-center gap-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 px-3 py-2.5">
                                <input type="checkbox" wire:model="editForm.required_permission_ids" value="{{ $permission->id }}"
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 h-3.5 w-3.5">
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $permission->name }}</span>
                            </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-gray-400">User must have all of the selected permissions.</p>
                    </div>
                    @endif
                </div>

                {{-- ═══ PUBLISHING ═══ --}}
                <div x-show="activeTab === 'publishing'" class="px-6 py-5 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Locale</label>
                        <input type="text" wire:model="editForm.locale" placeholder="e.g. en, fr, en-US" maxlength="10"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        @error('editForm.locale')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400">Leave blank to show in all locales.</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700">
                            <p class="text-xs font-bold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Publish window</p>
                            <p class="text-[11px] text-gray-400 mt-0.5">Leave both empty to always show this item.</p>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Show from</label>
                                    <input type="datetime-local" wire:model="editForm.publish_from"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2.5 py-2 text-xs text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                    @error('editForm.publish_from')
                                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Hide after</label>
                                    <input type="datetime-local" wire:model="editForm.publish_until"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2.5 py-2 text-xs text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                    @error('editForm.publish_until')
                                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div x-data="{
                                get status() {
                                    const from  = $wire.editForm.publish_from;
                                    const until = $wire.editForm.publish_until;
                                    if (!from && !until) return null;
                                    const now     = Date.now();
                                    const fromMs  = from  ? new Date(from).getTime()  : null;
                                    const untilMs = until ? new Date(until).getTime() : null;
                                    if (untilMs !== null && now > untilMs) return 'expired';
                                    if (fromMs  !== null && now < fromMs)  return 'scheduled';
                                    return 'active';
                                }
                            }">
                                <template x-if="status === 'scheduled'">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                                        </svg>
                                        Scheduled &mdash; not yet live
                                    </span>
                                </template>
                                <template x-if="status === 'expired'">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                        </svg>
                                        Expired &mdash; no longer shown
                                    </span>
                                </template>
                                <template x-if="status === 'active'">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                        Currently live
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ═══ ADVANCED ═══ --}}
                <div x-show="activeTab === 'advanced'" class="px-6 py-5 space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">Badge label</label>
                        <div class="flex gap-2 items-center">
                            <input wire:model="editForm.badge_text" type="text" placeholder="e.g. New, Sale, Beta" maxlength="50"
                                class="flex-1 px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                            <input wire:model="editForm.badge_color" type="color"
                                class="h-9 w-9 rounded-lg border border-gray-300 dark:border-gray-600 cursor-pointer p-0.5 bg-white dark:bg-gray-700 flex-shrink-0" title="Badge background color">
                        </div>
                        <p class="mt-1 text-xs text-gray-400">Leave blank to hide the badge.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">CSS Class</label>
                            <input wire:model="editForm.css_class" type="text" placeholder="my-link highlight"
                                class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1.5">HTML ID</label>
                            <input wire:model="editForm.css_id" type="text" placeholder="nav-home"
                                class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        </div>
                    </div>

                    <div class="rounded-xl border border-amber-200 dark:border-amber-800/50 bg-amber-50 dark:bg-amber-900/20 p-3.5">
                        <p class="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                            <strong>CSS Class</strong> and <strong>HTML ID</strong> are applied directly to the <code class="font-mono">&lt;a&gt;</code> element rendered by your theme.
                        </p>
                    </div>
                </div>

            </div>{{-- /scrollable --}}

            {{-- Footer --}}
            <div class="flex items-center justify-between gap-3 px-6 py-4 bg-gray-50 dark:bg-gray-800/80 border-t border-gray-200 dark:border-gray-700 flex-shrink-0">
                <button type="button" @click="$wire.cancelEdit()"
                    class="px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
                <button type="button" wire:click="saveItem" wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm font-bold text-white bg-primary-600 rounded-lg hover:bg-primary-700 disabled:opacity-60 transition-all active:scale-95 flex items-center gap-2">
                    <span wire:loading.remove wire:target="saveItem">Save changes</span>
                    <span wire:loading wire:target="saveItem" class="flex items-center gap-2">
                        <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Saving&hellip;
                    </span>
                </button>
            </div>

        </div>{{-- /panel --}}
    </div>{{-- /slide-over --}}

</div>

@script
<script>
    window.navigationMenuBuilder = function() {
        return {
            sortables: [],
            retryCount: 0,

            init() {
                this.$nextTick(() => this.initSortables());
                this.$wire.on('navigation-tree-updated', () => {
                    this.$nextTick(() => {
                        this.retryCount = 0;
                        this.initSortables();
                    });
                });
            },

            initSortables() {
                this.sortables.forEach(s => {
                    try {
                        s.destroy();
                    } catch (e) {}
                });
                this.sortables = [];

                if (typeof Sortable === 'undefined') {
                    if (this.retryCount < 20) {
                        this.retryCount++;
                        setTimeout(() => this.initSortables(), 150);
                    }
                    return;
                }

                this.retryCount = 0;
                const lists = this.$el.querySelectorAll('[data-sortable]');

                lists.forEach(list => {
                    const instance = new Sortable(list, {
                        group: {
                            name: 'nav-items',
                            put: true,
                            pull: true
                        },
                        handle: '[data-drag-handle]',
                        animation: 180,
                        easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
                        fallbackOnBody: true,
                        swapThreshold: 0.5,
                        ghostClass: 'sortable-ghost',
                        chosenClass: 'sortable-chosen',
                        dragClass: 'sortable-drag',
                        onStart: () => document.body.classList.add('is-dragging'),
                        onEnd: () => {
                            document.body.classList.remove('is-dragging');
                            this.save();
                        },
                    });
                    this.sortables.push(instance);
                });
            },

            save() {
                const root = this.$el.querySelector('[data-sortable-root]');
                if (!root) return;
                const items = this.serialize(root, null);
                if (items.length === 0) return;
                this.$wire.reorder(items);
            },

            serialize(list, parentId) {
                const items = [];
                let order = 0;
                list.querySelectorAll(':scope > [data-item-id]').forEach(el => {
                    const id = el.getAttribute('data-item-id');
                    items.push({
                        id,
                        parentId,
                        sortOrder: order++
                    });
                    const nested = el.querySelector('[data-sortable]');
                    if (nested) items.push(...this.serialize(nested, id));
                });
                return items;
            },
        };
    };
</script>
@endscript