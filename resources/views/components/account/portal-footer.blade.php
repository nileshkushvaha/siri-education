<footer class="relative py-6 text-sm text-fg-muted" data-account-footer>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[rgb(var(--portal-c)/.8)] to-transparent shadow-[0_-1px_12px_rgb(var(--portal-b)/.4)]" aria-hidden="true"></div>
    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-3 px-4 sm:px-6">
        <p>&copy; {{ now()->year }} {{ config('app.name') }}</p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Portal footer">
            @if(Route::has('page.show'))<a class="min-h-11 py-3 hover:text-fg-strong" href="{{ route('page.show', 'contact-us') }}">Support</a>@endif
            @if(Route::has('privacy'))<a class="min-h-11 py-3 hover:text-fg-strong" href="{{ route('privacy') }}">Privacy</a>@endif
            @if(Route::has('terms'))<a class="min-h-11 py-3 hover:text-fg-strong" href="{{ route('terms') }}">Terms</a>@endif
        </nav>
    </div>
</footer>
