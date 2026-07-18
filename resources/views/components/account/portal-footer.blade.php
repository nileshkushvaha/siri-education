<footer class="border-t border-white/[0.08] py-6 text-sm text-slate-400" data-account-footer>
    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-3 px-4 sm:px-6">
        <p>&copy; {{ now()->year }} {{ config('app.name') }}</p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Portal footer">
            @if(Route::has('forms.support'))<a class="min-h-11 py-3 hover:text-white" href="{{ route('forms.support') }}">Support</a>@endif
            @if(Route::has('privacy'))<a class="min-h-11 py-3 hover:text-white" href="{{ route('privacy') }}">Privacy</a>@endif
            @if(Route::has('terms'))<a class="min-h-11 py-3 hover:text-white" href="{{ route('terms') }}">Terms</a>@endif
        </nav>
    </div>
</footer>
