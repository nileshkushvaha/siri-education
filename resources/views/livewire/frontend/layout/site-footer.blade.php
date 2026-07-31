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

<footer class="relative overflow-hidden border-t border-white/10 bg-[#171717] text-slate-300" data-public-site-footer>
    <div class="pointer-events-none absolute inset-x-0 top-0 z-10 h-1 bg-gradient-to-r from-violet-600 via-fuchsia-500 to-blue-500" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 bg-cover bg-center lg:bg-right" style="background-image: url('{{ asset('images/footer-background.jpg') }}')" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 bg-black/80" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-black/50 via-black/25 to-transparent" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-14 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-10 lg:gap-y-8" data-footer-columns>
            <section aria-labelledby="public-footer-brand" data-footer-column>
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                    @if($logo)
                        <img src="{{ $logo }}" alt="{{ $appName }}" class="h-11 w-auto max-w-48 object-contain">
                    @else
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 via-violet-500 to-fuchsia-500 text-lg font-black text-white shadow-lg shadow-violet-900/40" aria-hidden="true">{{ mb_substr($appName, 0, 1) }}</span>
                    @endif
                    <span id="public-footer-brand" class="text-2xl font-extrabold tracking-tight text-white">{{ $appName }}</span>
                </a>

                <p class="mt-5 max-w-sm text-base leading-8 text-slate-300">
                    {{ $footerText ?: 'A trusted learning partner connecting students with expert instructors, flexible lessons, and a clear path to meaningful progress.' }}
                </p>

                @if(Route::has('auth.register'))
                    <div class="mt-7">
                        <a href="{{ route('auth.register') }}" class="inline-flex min-h-12 items-center rounded-xl bg-gradient-to-r from-indigo-500 to-violet-500 px-5 text-base font-bold text-white shadow-lg shadow-violet-950/30 transition hover:-translate-y-0.5 hover:shadow-violet-500/20 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">Start learning</a>
                    </div>
                @endif
            </section>

            <section aria-labelledby="footer-explore" data-footer-column>
                <h2 id="footer-explore" class="text-2xl font-black text-white">Explore</h2>
                <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true"></span>
                <div class="mt-5 space-y-8">
                    @forelse($navigationGroups as $group)
                        <div>
                            @if($group['heading'])
                                <div class="mb-4">
                                    @if(! $group['heading']->link->isEmpty())
                                        <a href="{{ $group['heading']->link->url }}" class="inline-flex items-center gap-2 text-base font-bold uppercase tracking-wide text-white transition hover:text-violet-200">
                                            {{ $group['heading']->label }}
                                        </a>
                                    @else
                                        <h3 class="text-base font-bold uppercase tracking-wide text-white">{{ $group['heading']->label }}</h3>
                                    @endif
                                </div>
                            @endif
                            <nav aria-label="{{ $group['heading']?->label ?? 'Footer navigation' }}">
                                <ul class="space-y-3" role="list">
                                    @foreach($group['nodes'] as $node)
                                        @include('livewire.frontend.layout.partials.footer-nav-node', ['node' => $node])
                                    @endforeach
                                </ul>
                            </nav>
                        </div>
                    @empty
                        <p class="text-base leading-7 text-slate-300">Navigation links can be managed from the footer menu in Admin.</p>
                    @endforelse
                </div>
            </section>

            <section aria-labelledby="footer-learning" data-footer-column>
                <h2 id="footer-learning" class="text-2xl font-black text-white">Learning</h2>
                <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true"></span>
                @if($latestPosts->isNotEmpty())
                    <ul class="mt-5 space-y-3" role="list">
                        @foreach($latestPosts->take(5) as $post)
                            <li>
                                <a href="{{ route('blog.show', $post->slug) }}" class="text-base font-medium text-slate-300 transition hover:text-violet-200">{{ $post->title }}</a>
                            </li>
                        @endforeach
                        @if(Route::has('blog.index'))
                            <li><a href="{{ route('blog.index') }}" class="text-base font-bold text-violet-300 transition hover:text-white">View all articles →</a></li>
                        @endif
                    </ul>
                @else
                    <ul class="mt-5 space-y-3 text-base text-slate-300" role="list">
                        <li><a href="{{ route('instructors.index') }}" class="transition hover:text-violet-200">Find an instructor</a></li>
                        @if(Route::has('faqs.index'))<li><a href="{{ route('faqs.index') }}" class="transition hover:text-violet-200">Learning FAQs</a></li>@endif
                        @if(Route::has('blog.index'))<li><a href="{{ route('blog.index') }}" class="transition hover:text-violet-200">Learning resources</a></li>@endif
                    </ul>
                @endif
            </section>

            <section aria-labelledby="footer-contact" class="lg:col-start-4 lg:row-span-2 lg:row-start-1" data-footer-column>
                <h2 id="footer-contact" class="text-2xl font-black text-white">Get in touch</h2>
                <span class="mt-3 block h-1 w-10 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true"></span>
                <p class="mt-5 text-base leading-7 text-slate-300">Need help with learning, teaching, or your account? Contact our support team directly.</p>

                <address class="mt-5 space-y-3 not-italic">
                    @if($supportEmail)
                        <a href="mailto:{{ $supportEmail }}" class="group flex min-h-12 items-center gap-3 rounded-xl border border-white/[0.07] bg-white/[0.035] px-3 text-base text-slate-200 transition hover:border-violet-400/30 hover:bg-violet-500/[0.08] hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                            <svg class="h-5 w-5 shrink-0 text-violet-400 transition group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0-8.69 5.52a2 2 0 0 1-2.12 0L2.25 6.75"/></svg>
                            <span class="min-w-0 break-all">{{ $supportEmail }}</span>
                        </a>
                    @endif
                    @if($supportPhone)
                        <a href="tel:{{ preg_replace('/[^+0-9]/', '', $supportPhone) }}" class="group flex min-h-12 items-center gap-3 rounded-xl border border-white/[0.07] bg-white/[0.035] px-3 text-base text-slate-200 transition hover:border-violet-400/30 hover:bg-violet-500/[0.08] hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/25">
                            <svg class="h-5 w-5 shrink-0 text-indigo-400 transition group-hover:rotate-6 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.77.542-1.21.378a12.035 12.035 0 0 1-7.143-7.143c-.164-.44.002-.928.378-1.21l1.293-.97c.362-.271.527-.734.417-1.173L6.963 3.102A1.125 1.125 0 0 0 5.872 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                            <span>{{ $supportPhone }}</span>
                        </a>
                    @endif
                    @if($address)
                        <div class="flex gap-3 rounded-xl border border-white/[0.07] bg-white/[0.035] px-3 py-3 text-base leading-7 text-slate-300">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-fuchsia-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 21s7.5-4.35 7.5-11.25a7.5 7.5 0 1 0-15 0C4.5 16.65 12 21 12 21Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M14.25 9.75a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                            <span>{{ $address }}</span>
                        </div>
                    @endif
                </address>
            </section>

            <section aria-label="Newsletter signup" class="border-t border-white/10 pt-6 sm:col-span-2 lg:col-span-3 lg:row-start-2" data-footer-newsletter>
                <livewire:frontend.cms.newsletter
                    :compact="true"
                    eyebrow="Newsletter"
                    title="Stay inspired"
                    description="Learning ideas and platform updates — no spam."
                    email-label="Email address"
                    email-placeholder="you@example.com"
                    button-text="Subscribe"
                    success-message="Thanks — you're subscribed."
                />
            </section>
        </div>
    </div>

    <div class="relative border-t border-white/[0.08] bg-white/[0.035]" data-footer-bottom>
        <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 text-sm text-slate-400 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <p>{!! $footerCopyright ?: '&copy; '.date('Y').' '.e($appName).'. All rights reserved.' !!}</p>
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Legal links">
                <a href="{{ Route::has('privacy') ? route('privacy') : url('/privacy-policy') }}" class="transition hover:text-slate-300">Privacy Policy</a>
                <a href="{{ Route::has('terms') ? route('terms') : url('/terms-of-service') }}" class="transition hover:text-slate-300">Terms of Service</a>
                @if(Route::has('search.index'))<a href="{{ route('search.index') }}" class="transition hover:text-slate-300">Search</a>@endif
            </nav>
        </div>
    </div>
</footer>
