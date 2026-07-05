{{--
    Error banner for API failures. Shows the wizard's `banner` message;
    optional retry hook via the `retry` Alpine expression prop.
--}}
@props(['retry' => null])

<div x-show="banner" x-cloak role="alert" aria-live="assertive"
     class="mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-400/20 dark:bg-red-400/10">
    <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-500 dark:text-red-300" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
        <path fill-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-8-5a1 1 0 00-1 1v4a1 1 0 002 0V6a1 1 0 00-1-1zm0 10a1.25 1.25 0 100-2.5 1.25 1.25 0 000 2.5z" clip-rule="evenodd"/>
    </svg>
    <div class="flex-1 text-sm text-red-700 dark:text-red-200">
        <p x-text="banner"></p>
        @if($retry)
            <button type="button" @click="{{ $retry }}"
                    class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-red-800 underline underline-offset-2 hover:text-red-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300 rounded dark:text-red-100 dark:hover:text-white dark:focus-visible:ring-red-400/30">
                Try again
            </button>
        @endif
    </div>
    <button type="button" @click="banner = ''" aria-label="Dismiss error"
            class="text-red-400 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300 rounded dark:text-red-300 dark:hover:text-red-100 dark:focus-visible:ring-red-400/30">
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
    </button>
</div>
