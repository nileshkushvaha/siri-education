<div class="space-y-6">
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
