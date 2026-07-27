<div class="space-y-6">
    {{-- Availability-change impact warning. Shown when a proposed reduction affects confirmed upcoming lessons; nothing has been changed yet. --}}
    @if($pendingImpact !== null)
        <div role="alert" aria-live="assertive" class="rounded-2xl border border-red-400/40 bg-red-500/10 p-5 text-sm text-red-100">
            <p class="font-bold text-red-200">
                <svg class="mr-1 inline h-4 w-4 align-text-bottom" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                This change affects {{ $pendingImpact['count'] }} confirmed upcoming {{ Str::plural('lesson', $pendingImpact['count']) }}.
            </p>
            <p class="mt-2 leading-6">
                Those lessons <span class="font-semibold">remain scheduled and unchanged</span> — you are still expected to teach them, or handle
                them through the normal booking workflow (reschedule or cancellation). Saving this change only affects future bookings.
            </p>
            @if($pendingImpact['summaries'] !== [])
                <ul class="mt-3 list-disc space-y-1 pl-5 text-red-200/90">
                    @foreach($pendingImpact['summaries'] as $summary)
                        <li>{{ $summary['starts_at'] }} <span class="text-red-300/70">(booking {{ $summary['reference'] }})</span></li>
                    @endforeach
                    @if($pendingImpact['count'] > count($pendingImpact['summaries']))
                        <li class="list-none text-red-300/70">…and {{ $pendingImpact['count'] - count($pendingImpact['summaries']) }} more.</li>
                    @endif
                </ul>
            @endif
            <div class="mt-4 flex flex-wrap gap-3">
                <button type="button" wire:click="confirmPendingImpact" wire:loading.attr="disabled"
                        class="rounded-xl bg-red-500 px-4 py-2 text-sm font-bold text-white hover:bg-red-400 disabled:opacity-50">
                    Confirm change
                </button>
                <button type="button" wire:click="cancelPendingImpact" wire:loading.attr="disabled"
                        class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10 disabled:opacity-50">
                    Go back
                </button>
            </div>
        </div>
    @endif

    @if($isOnVacation)
        <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-200">
            <p class="font-semibold">Vacation mode is enabled.</p>
            <p class="mt-1 leading-6">
                Your weekly availability is saved but new bookings are paused.
                <a href="{{ route('dashboard.instructor.vacation') }}" class="underline hover:text-amber-100">Manage vacation mode</a>
            </p>
        </div>
    @endif

    @unless($hasProfileTimezone)
        <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-200">
            <p class="font-semibold">Your profile timezone is not set.</p>
            <p class="mt-1 leading-6">
                Choose a timezone below for each window, or
                <a href="{{ route('profile.show') }}" class="underline hover:text-amber-100">set your profile timezone</a>
                so it fills in automatically next time. A timezone is required before you can add a weekly window.
            </p>
        </div>
    @endunless

    <x-account.card>
        <div class="grid gap-4 lg:grid-cols-[1fr_14rem] lg:items-end">
            <div>
                <h2 class="text-lg font-semibold text-white">Weekly schedule</h2>
                <p class="mt-1 text-sm leading-6 text-slate-400">
                    Add recurring windows in your local teaching time. Published windows are used by the booking engine to generate slots.
                </p>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Timezone</label>
                <select wire:model.live="timezone" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-2 text-sm text-white outline-none focus:border-indigo-400">
                    <option value="">Select timezone…</option>
                    @foreach(timezone_identifiers_list() as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
                @error('timezone') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>
        </div>

        <form wire:submit.prevent="addWindow" class="mt-6 grid gap-4 lg:grid-cols-6">
            <div class="lg:col-span-1">
                <label class="block text-sm font-medium text-slate-300">Day</label>
                <select wire:model="dayOfWeek" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                    @foreach($weekdays as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('dayOfWeek') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">Start</label>
                <input wire:model="startTime" type="time" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                @error('startTime') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">End</label>
                <input wire:model="endTime" type="time" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                @error('endTime') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">Starts on</label>
                <input wire:model="effectiveFrom" type="date" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                @error('effectiveFrom') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300">Ends on</label>
                <input wire:model="effectiveUntil" type="date" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                @error('effectiveUntil') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3 text-sm font-semibold text-white transition hover:from-indigo-500 hover:to-violet-500">
                    Add window
                </button>
            </div>
        </form>

        <div class="mt-6 overflow-hidden rounded-2xl border border-white/[0.07]">
            @forelse($windows as $window)
                <div wire:key="availability-window-{{ $window['id'] }}" class="grid gap-3 border-b border-white/[0.06] p-4 last:border-b-0 md:grid-cols-[1fr_1fr_1fr_auto] md:items-center">
                    <div>
                        <p class="text-sm font-semibold text-white">{{ $window['day'] }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $window['effective'] }}</p>
                    </div>
                    <p class="text-sm text-slate-300">{{ $window['time'] }}</p>
                    <p class="text-xs text-slate-400">{{ $window['timezone'] }}</p>
                    <div class="flex gap-2 md:justify-end">
                        <button type="button" wire:click="toggleWindow('{{ $window['id'] }}')" class="rounded-lg border border-white/[0.10] px-3 py-2 text-xs font-semibold {{ $window['active'] ? 'text-emerald-300' : 'text-slate-300' }} hover:bg-white/[0.05]">
                            {{ $window['active'] ? 'Published' : 'Draft' }}
                        </button>
                        <button type="button" wire:click="deleteWindow('{{ $window['id'] }}')" wire:confirm="Remove this availability window?" class="rounded-lg border border-rose-400/30 px-3 py-2 text-xs font-semibold text-rose-300 hover:bg-rose-500/10">
                            Remove
                        </button>
                    </div>
                </div>
            @empty
                <div class="p-6 text-sm text-slate-400">
                    No weekly windows yet. Add your first teaching window above.
                </div>
            @endforelse
        </div>
    </x-account.card>

    <x-account.card>
        <div>
            <h2 class="text-lg font-semibold text-white">Time off</h2>
            <p class="mt-1 text-sm leading-6 text-slate-400">
                Block one-off periods for leave, travel, or private commitments. These periods remove generated slots.
            </p>
        </div>

        <form wire:submit.prevent="addTimeOff" class="mt-6 grid gap-4 lg:grid-cols-[1fr_1fr_1fr_auto]">
            <div>
                <label class="block text-sm font-medium text-slate-300">Starts</label>
                <input wire:model="timeOffStartsAt" type="datetime-local" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                @error('timeOffStartsAt') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300">Ends</label>
                <input wire:model="timeOffEndsAt" type="datetime-local" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none focus:border-indigo-400">
                @error('timeOffEndsAt') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300">Reason</label>
                <input wire:model="timeOffReason" type="text" placeholder="Optional private note" class="mt-2 w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-indigo-400">
                @error('timeOffReason') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl border border-white/[0.10] px-4 py-3 text-sm font-semibold text-white transition hover:bg-white/[0.05]">
                    Add block
                </button>
            </div>
        </form>

        <div class="mt-6 overflow-hidden rounded-2xl border border-white/[0.07]">
            @forelse($timeOff as $leave)
                <div wire:key="availability-leave-{{ $leave['id'] }}" class="grid gap-3 border-b border-white/[0.06] p-4 last:border-b-0 md:grid-cols-[1fr_1fr_auto] md:items-center">
                    <div>
                        <p class="text-sm font-semibold text-white">{{ $leave['range'] }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $leave['timezone'] }}</p>
                    </div>
                    <p class="text-sm text-slate-400">{{ $leave['reason'] ?: 'No private note' }}</p>
                    <button type="button" wire:click="deleteTimeOff('{{ $leave['id'] }}')" wire:confirm="Remove this time off block?" class="rounded-lg border border-rose-400/30 px-3 py-2 text-xs font-semibold text-rose-300 hover:bg-rose-500/10">
                        Remove
                    </button>
                </div>
            @empty
                <div class="p-6 text-sm text-slate-400">
                    No upcoming time off. Add a block only when you need to remove availability.
                </div>
            @endforelse
        </div>
    </x-account.card>
</div>
