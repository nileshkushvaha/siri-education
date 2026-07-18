@php
    $footerNavigation = $this->footerNavigation;
    $latestPosts = $this->latestPosts;
    $navigationGroups = [];
    $standaloneNodes = [];

    foreach ($footerNavigation?->nodes ?? [] as $node) {
        if ($node->hasChildren()) {
            $navigationGroups[] = ['heading' => $node, 'nodes' => $node->children];
        } else {
            $standaloneNodes[] = $node;
        }
    }

    if ($standaloneNodes !== []) {
        array_unshift($navigationGroups, ['heading' => null, 'nodes' => $standaloneNodes]);
    }
@endphp

<footer class="relative overflow-hidden border-t border-white/10 bg-[#07051f] text-slate-300" data-public-site-footer>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/80 to-transparent shadow-[0_1px_18px_rgba(139,92,246,.55)]" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -left-32 top-16 h-80 w-80 rounded-full bg-indigo-600/10 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-32 bottom-20 h-80 w-80 rounded-full bg-fuchsia-600/10 blur-3xl" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8 lg:py-20">
        <div class="grid gap-12 lg:grid-cols-12 lg:gap-8">
            <section class="lg:col-span-4" aria-labelledby="public-footer-brand">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                    @if($logo)
                        <img src="{{ $logo }}" alt="{{ $appName }}" class="h-11 w-auto max-w-48 object-contain">
                    @else
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 via-violet-500 to-fuchsia-500 text-lg font-black text-white shadow-lg shadow-violet-900/40" aria-hidden="true">{{ mb_substr($appName, 0, 1) }}</span>
                    @endif
                    <span id="public-footer-brand" class="text-xl font-extrabold tracking-tight text-white">{{ $appName }}</span>
                </a>

                <p class="mt-5 max-w-sm text-sm leading-7 text-slate-400">
                    {{ $footerText ?: 'Learn with trusted instructors, flexible schedules, secure bookings, and a clear path from your first goal to meaningful progress.' }}
                </p>

                <div class="mt-7 flex flex-wrap gap-3">
                    @if(Route::has('auth.register'))
                        <a href="{{ route('auth.register') }}" class="inline-flex min-h-11 items-center rounded-xl bg-gradient-to-r from-indigo-500 to-violet-500 px-4 text-sm font-bold text-white shadow-lg shadow-violet-950/30 transition hover:-translate-y-0.5 hover:shadow-violet-500/20 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">Start learning</a>
                    @endif
                    @if(Route::has('instructors.index'))
                        <a href="{{ route('instructors.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-white/10 bg-white/[0.04] px-4 text-sm font-semibold text-slate-200 transition hover:border-violet-400/30 hover:bg-white/[0.08] hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">Find an instructor</a>
                    @endif
                </div>
            </section>

            <div class="grid gap-10 sm:grid-cols-2 lg:col-span-5 lg:gap-8" aria-label="Footer links">
                @forelse($navigationGroups as $group)
                    <section aria-labelledby="footer-navigation-{{ $loop->index }}">
                        <div class="mb-5">
                            @if($group['heading'] && ! $group['heading']->link->isEmpty())
                                <a id="footer-navigation-{{ $loop->index }}" href="{{ $group['heading']->link->url }}" class="text-base font-bold text-white transition hover:text-violet-200">
                                    {{ $group['heading']->label }}
                                </a>
                            @else
                                <h2 id="footer-navigation-{{ $loop->index }}" class="text-base font-bold text-white">{{ $group['heading']?->label ?? 'Explore' }}</h2>
                            @endif
                            <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-indigo-500 to-fuchsia-500" aria-hidden="true"></span>
                        </div>
                        <nav aria-label="{{ $group['heading']?->label ?? 'Footer navigation' }}">
                            <ul class="space-y-3" role="list">
                                @foreach($group['nodes'] as $node)
                                    @include('livewire.frontend.layout.partials.footer-nav-node', ['node' => $node])
                                @endforeach
                            </ul>
                        </nav>
                    </section>
                @empty
                    <section aria-labelledby="footer-navigation-empty">
                        <h2 id="footer-navigation-empty" class="text-base font-bold text-white">Explore</h2>
                        <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-indigo-500 to-fuchsia-500" aria-hidden="true"></span>
                        <p class="mt-5 text-sm leading-6 text-slate-400">Navigation links can be managed from the footer menu in Admin.</p>
                    </section>
                @endforelse

                @if($latestPosts->isNotEmpty())
                    <section aria-labelledby="footer-latest-posts">
                        <h2 id="footer-latest-posts" class="text-base font-bold text-white">Latest insights</h2>
                        <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-indigo-500 to-fuchsia-500" aria-hidden="true"></span>
                        <div class="mt-5 space-y-4">
                            @foreach($latestPosts as $post)
                                <a href="{{ route('blog.show', $post->slug) }}" class="group block rounded-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                                    <span class="block text-sm font-medium leading-5 text-slate-400 transition group-hover:text-violet-200">{{ $post->title }}</span>
                                    @if($post->published_at)
                                        <span class="mt-1 block text-xs text-slate-500">{{ $post->published_at->format('M j, Y') }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <section class="lg:col-span-3" aria-labelledby="footer-contact">
                <h2 id="footer-contact" class="text-base font-bold text-white">Get in touch</h2>
                <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-indigo-500 to-fuchsia-500" aria-hidden="true"></span>
                <p class="mt-5 text-sm leading-6 text-slate-400">Questions about learning, teaching, or your account? Our support team is ready to help.</p>

                <address class="mt-5 space-y-3 not-italic">
                    @if($supportEmail)
                        <a href="mailto:{{ $supportEmail }}" class="group flex min-h-11 items-center gap-3 rounded-xl border border-white/[0.07] bg-white/[0.035] px-3 text-sm text-slate-300 transition hover:border-violet-400/30 hover:bg-violet-500/[0.08] hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                            <svg class="h-5 w-5 shrink-0 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0-8.69 5.52a2 2 0 0 1-2.12 0L2.25 6.75"/></svg>
                            <span class="min-w-0 break-all">{{ $supportEmail }}</span>
                        </a>
                    @endif
                    @if($supportPhone)
                        <a href="tel:{{ preg_replace('/[^+0-9]/', '', $supportPhone) }}" class="flex min-h-11 items-center gap-3 rounded-xl border border-white/[0.07] bg-white/[0.035] px-3 text-sm text-slate-300 transition hover:border-violet-400/30 hover:bg-violet-500/[0.08] hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                            <svg class="h-5 w-5 shrink-0 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.77.542-1.21.378a12.035 12.035 0 0 1-7.143-7.143c-.164-.44.002-.928.378-1.21l1.293-.97c.362-.271.527-.734.417-1.173L6.963 3.102A1.125 1.125 0 0 0 5.872 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                            <span>{{ $supportPhone }}</span>
                        </a>
                    @endif
                    @if($address)
                        <div class="flex gap-3 rounded-xl border border-white/[0.07] bg-white/[0.035] px-3 py-3 text-sm leading-6 text-slate-400">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-fuchsia-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 21s7.5-4.35 7.5-11.25a7.5 7.5 0 1 0-15 0C4.5 16.65 12 21 12 21Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M14.25 9.75a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                            <span>{{ $address }}</span>
                        </div>
                    @endif
                    @if(! $supportEmail && ! $supportPhone && ! $address)
                        <p class="rounded-xl border border-dashed border-white/10 p-4 text-sm leading-6 text-slate-400">Contact details can be configured in General Settings.</p>
                    @endif
                </address>

                <nav class="mt-5 flex flex-wrap gap-x-4 gap-y-2 text-sm" aria-label="Contact options">
                    @if(Route::has('forms.support'))<a href="{{ route('forms.support') }}" class="font-medium text-violet-300 transition hover:text-white">Support</a>@endif
                    @if(Route::has('forms.callback'))<a href="{{ route('forms.callback') }}" class="font-medium text-violet-300 transition hover:text-white">Request a callback</a>@endif
                    @if(Route::has('forms.inquiry'))<a href="{{ route('forms.inquiry') }}" class="font-medium text-violet-300 transition hover:text-white">Inquiry</a>@endif
                </nav>
            </section>
        </div>
    </div>

    <div class="relative border-t border-white/[0.08] bg-white/[0.035]">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-6 text-xs text-slate-500 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <p>{!! $footerCopyright ?: '&copy; '.date('Y').' '.e($appName).'. All rights reserved.' !!}</p>
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Legal links">
                <a href="{{ Route::has('privacy') ? route('privacy') : url('/privacy-policy') }}" class="transition hover:text-slate-300">Privacy Policy</a>
                <a href="{{ Route::has('terms') ? route('terms') : url('/terms-of-service') }}" class="transition hover:text-slate-300">Terms of Service</a>
                @if(Route::has('search.index'))<a href="{{ route('search.index') }}" class="transition hover:text-slate-300">Search</a>@endif
            </nav>
        </div>
    </div>
</footer>
