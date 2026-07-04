{{--
    Wizard progress indicator. Expects the Alpine scope to expose:
    `step` (current 1-based step) and `steps` (array of labels).
    Dots + connector on mobile, labels from md: up.
--}}
<nav aria-label="Booking progress">
    <ol class="flex items-center justify-between gap-1 sm:gap-2" role="list">
        <template x-for="(label, i) in steps" :key="label">
            <li class="flex items-center flex-1 last:flex-none"
                :aria-current="step === i + 1 ? 'step' : null">
                <div class="flex flex-col items-center gap-1.5 min-w-0">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-colors duration-200"
                          :class="step > i + 1
                                    ? 'bg-emerald-500 text-white'
                                    : (step === i + 1 ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-slate-200 text-slate-400')">
                        <svg x-show="step > i + 1" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        <span x-show="step <= i + 1" x-text="i + 1"></span>
                    </span>
                    <span class="hidden md:block text-[11px] font-medium truncate max-w-20 text-center"
                          :class="step === i + 1 ? 'text-indigo-700' : 'text-slate-400'"
                          x-text="label"></span>
                </div>
                <span class="mx-1 sm:mx-2 h-0.5 flex-1 rounded transition-colors duration-200 last:hidden"
                      :class="step > i + 1 ? 'bg-emerald-400' : 'bg-slate-200'"
                      x-show="i < steps.length - 1" aria-hidden="true"></span>
            </li>
        </template>
    </ol>
</nav>
