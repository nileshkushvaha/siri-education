<div>
    @if($visible)
        <section class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200 bg-white/95 p-4 shadow-2xl shadow-slate-900/10 backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/95" aria-label="Cookie notice">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                    We use essential cookies to keep this site reliable and secure. Optional analytics may be enabled through configured platform settings.
                </p>
                <div class="flex shrink-0 gap-2">
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="dismiss">Dismiss</x-ui.button>
                    <x-ui.button type="button" size="sm" wire:click="accept">Accept</x-ui.button>
                </div>
            </div>
        </section>
    @endif
</div>
