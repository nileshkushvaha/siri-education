<div
    x-data="{
        activeIndex: -1,
        resultEls() { return Array.from(this.$el.querySelectorAll('[data-search-result]')) },
        clearActive(els) { els.forEach(el => el.classList.remove('bg-slate-100', 'dark:bg-white/10')) },
        move(delta) {
            const els = this.resultEls();
            if (!els.length) { this.activeIndex = -1; return; }
            this.clearActive(els);
            this.activeIndex = ((this.activeIndex + delta) + els.length) % els.length;
            const el = els[this.activeIndex];
            el.classList.add('bg-slate-100', 'dark:bg-white/10');
            el.scrollIntoView({ block: 'nearest' });
        },
        select() {
            const els = this.resultEls();
            const el = els[this.activeIndex];
            if (el) el.click();
        },
        resetActive() {
            this.clearActive(this.resultEls());
            this.activeIndex = -1;
        },
    }"
    x-init="$watch('$wire.query', () => resetActive())"
>
    @if($open)
        <div class="fixed inset-0 z-[60] overflow-y-auto bg-slate-950/75 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="public-search-title" wire:keydown.escape="close">
        <div class="mx-auto mt-10 max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-slate-950">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-white/10">
                <h2 id="public-search-title" class="text-sm font-semibold text-slate-900 dark:text-white">Search</h2>
                <button type="button" wire:click="close" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:hover:text-slate-200 dark:focus-visible:ring-indigo-400/20">
                    <span class="sr-only">Close search</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="p-4">
                <label for="public-search-query" class="sr-only">Search query</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m0 0A7.5 7.5 0 105.2 5.2a7.5 7.5 0 0010.6 10.6z"/></svg>
                    <input id="public-search-query" type="search" wire:model.live.debounce.250ms="query"
                           x-on:keydown.arrow-down.prevent="move(1)"
                           x-on:keydown.arrow-up.prevent="move(-1)"
                           x-on:keydown.enter.prevent="select()"
                           class="min-h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-base text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20"
                           placeholder="Search teachers, courses, pages, FAQs..." autofocus>
                </div>

                <div class="mt-4 space-y-5">
                    @if(trim($query) === '')
                        <p class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">Start typing to search teachers, courses, pages, and FAQs.</p>
                    @else
                        @if(! $this->hasResults)
                            <x-ui.empty-state title="No results" description="Try a different keyword or open the full search page.">
                                <x-ui.button href="{{ route('search.index', ['q' => $query]) }}" size="sm">Full search</x-ui.button>
                            </x-ui.empty-state>
                        @endif

                        @if($this->results['teachers']->isNotEmpty())
                            <section>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Teachers</h3>
                                <div class="space-y-1">
                                    @foreach($this->results['teachers'] as $teacher)
                                        <a href="{{ $teacher['url'] }}" data-search-result class="block rounded-xl px-3 py-2 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:focus-visible:ring-indigo-400/20">
                                            <span class="block text-sm font-semibold text-slate-900 dark:text-white"><x-ui.search-highlight :text="$teacher['name']" :term="$query" /></span>
                                            @if($teacher['headline'])
                                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $teacher['headline'] }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        {{-- Courses always shown once a query is typed — no Course
                             domain exists yet, so this is an explicit stub rather
                             than silently omitting the category. --}}
                        <section>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Courses</h3>
                            <p class="px-3 py-2 text-sm text-slate-500 dark:text-slate-400">Course search is coming soon.</p>
                        </section>

                        @if($this->results['pages']->isNotEmpty())
                            <section>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Pages</h3>
                                <div class="space-y-1">
                                    @foreach($this->results['pages'] as $page)
                                        <a href="{{ $page->slug === 'home' ? route('home') : route('page.show', $page->slug) }}" data-search-result class="block rounded-xl px-3 py-2 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:focus-visible:ring-indigo-400/20">
                                            <span class="block text-sm font-semibold text-slate-900 dark:text-white"><x-ui.search-highlight :text="$page->title" :term="$query" /></span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">/{{ $page->slug }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if($this->results['posts']->isNotEmpty())
                            <section>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Posts</h3>
                                <div class="space-y-1">
                                    @foreach($this->results['posts'] as $post)
                                        <a href="{{ route('blog.show', $post->slug) }}" data-search-result class="block rounded-xl px-3 py-2 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:focus-visible:ring-indigo-400/20">
                                            <span class="block text-sm font-semibold text-slate-900 dark:text-white"><x-ui.search-highlight :text="$post->title" :term="$query" /></span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">/blog/{{ $post->slug }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if($this->results['faqs']->isNotEmpty())
                            <section>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">FAQs</h3>
                                <div class="space-y-1">
                                    @foreach($this->results['faqs'] as $faq)
                                        <a href="{{ route('faqs.index', ['q' => $query]) }}#faq-{{ $faq->id }}" data-search-result class="block rounded-xl px-3 py-2 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:focus-visible:ring-indigo-400/20">
                                            <span class="block text-sm font-semibold text-slate-900 dark:text-white"><x-ui.search-highlight :text="$faq->question" :term="$query" /></span>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        <div class="border-t border-slate-200 pt-4 dark:border-white/10">
                            <x-ui.button href="{{ route('search.index', ['q' => $query]) }}" variant="secondary" size="sm">Open full search</x-ui.button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        </div>
    @endif
</div>
