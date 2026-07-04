@php
    $footerNavigation = $this->footerNavigation;
    $latestPosts = $this->latestPosts;
@endphp

<footer class="border-t border-white/10 bg-slate-950 text-slate-300">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
            <section aria-labelledby="footer-brand">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/20">
                    @if($logo)
                        <img src="{{ $logo }}" alt="{{ $appName }}" class="h-9 w-auto max-w-40 object-contain">
                    @else
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-600 to-violet-600 font-black text-white">{{ mb_substr($appName, 0, 1) }}</span>
                    @endif
                    <span id="footer-brand" class="font-bold text-white">{{ $appName }}</span>
                </a>

                <p class="mt-4 max-w-sm text-sm leading-6 text-slate-400">
                    {{ $footerText ?: 'Building personalised learning experiences with dependable, accessible digital tools.' }}
                </p>
            </section>

            <section aria-labelledby="footer-navigation">
                <h2 id="footer-navigation" class="text-sm font-semibold uppercase tracking-wider text-white">Explore</h2>
                @if($footerNavigation && ! $footerNavigation->isEmpty())
                    <nav class="mt-4" aria-label="Footer navigation">
                        <ul class="space-y-2" role="list">
                            @foreach($footerNavigation->nodes as $node)
                                @include('livewire.frontend.layout.partials.footer-nav-node', ['node' => $node])
                            @endforeach
                        </ul>
                    </nav>
                @else
                    <p class="mt-4 text-sm text-slate-400">Footer navigation is not configured yet.</p>
                @endif
            </section>

            <section aria-labelledby="footer-posts">
                <h2 id="footer-posts" class="text-sm font-semibold uppercase tracking-wider text-white">Latest Posts</h2>
                <div class="mt-4 space-y-4">
                    @forelse($latestPosts as $post)
                        <a href="{{ route('blog.show', $post->slug) }}" class="group block rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/20">
                            <span class="block text-sm font-medium text-slate-200 transition group-hover:text-indigo-200">{{ $post->title }}</span>
                            @if($post->published_at)
                                <span class="mt-1 block text-xs text-slate-400">{{ $post->published_at->format('M j, Y') }}</span>
                            @endif
                        </a>
                    @empty
                        <p class="text-sm text-slate-400">No posts yet.</p>
                    @endforelse
                </div>
            </section>

            <section aria-labelledby="footer-contact">
                <h2 id="footer-contact" class="text-sm font-semibold uppercase tracking-wider text-white">Contact</h2>
                <address class="mt-4 space-y-3 text-sm not-italic text-slate-400">
                    @if($supportEmail)
                        <p><a href="mailto:{{ $supportEmail }}" class="transition hover:text-indigo-200">{{ $supportEmail }}</a></p>
                    @endif
                    @if($supportPhone)
                        <p><a href="tel:{{ $supportPhone }}" class="transition hover:text-indigo-200">{{ $supportPhone }}</a></p>
                    @endif
                    @if($address)
                        <p>{{ $address }}</p>
                    @endif
                    @if(! $supportEmail && ! $supportPhone && ! $address)
                        <p>Configure contact details in General Settings.</p>
                    @endif
                </address>
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                    <a href="{{ route('forms.support') }}" class="text-slate-400 transition hover:text-indigo-200">Support</a>
                    <a href="{{ route('forms.callback') }}" class="text-slate-400 transition hover:text-indigo-200">Request a Callback</a>
                    <a href="{{ route('forms.inquiry') }}" class="text-slate-400 transition hover:text-indigo-200">General Inquiry</a>
                    <a href="{{ route('forms.feedback') }}" class="text-slate-400 transition hover:text-indigo-200">Feedback</a>
                </div>
            </section>
        </div>

        <div class="mt-12 flex flex-col gap-4 border-t border-white/10 pt-6 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between">
            <p>{!! $footerCopyright ?: '&copy; '.date('Y').' '.e($appName).'. All rights reserved.' !!}</p>
            <div class="flex flex-wrap gap-4">
                <a href="{{ url('/privacy-policy') }}" class="transition hover:text-slate-300">Privacy</a>
                <a href="{{ url('/terms-of-service') }}" class="transition hover:text-slate-300">Terms</a>
                @if(Route::has('search.index'))
                    <a href="{{ route('search.index') }}" class="transition hover:text-slate-300">Search</a>
                @endif
            </div>
        </div>
    </div>
</footer>
