<div class="space-y-6">
    @if (session('vacation-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200" role="status">
            {{ session('vacation-status') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200" role="alert">
            {{ $message }}
        </div>
    @enderror

    <x-account.card>
        @if ($status === \App\Enums\InstructorStatus::Active)
            <div class="flex items-center gap-2 mb-1">
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                <h2 class="text-lg font-semibold text-white">Available</h2>
            </div>
            <p class="mt-2 text-sm leading-6 text-slate-400">You are currently accepting new students.</p>

            @if (! $confirmingEnable)
                <button type="button" wire:click="confirmEnable"
                        class="mt-5 min-h-11 inline-flex items-center rounded-xl bg-indigo-500 px-4 text-sm font-semibold text-white hover:bg-indigo-400 transition">
                    Enable Vacation Mode
                </button>
            @else
                <div class="mt-5 rounded-xl border border-amber-400/20 bg-amber-500/10 p-4">
                    <p class="text-sm font-semibold text-white">Are you sure?</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">New students will not be able to book lessons while vacation mode is active.</p>
                    <p class="mt-1 text-sm leading-6 text-slate-300">Existing scheduled lessons are not affected.</p>
                    @if($upcomingConfirmedLessons > 0)
                        <p class="mt-1 text-sm leading-6 text-amber-200" role="status">
                            You currently have {{ $upcomingConfirmedLessons }} confirmed upcoming {{ Str::plural('lesson', $upcomingConfirmedLessons) }} —
                            these remain scheduled and you are still expected to teach them while on vacation.
                        </p>
                    @endif
                    <div class="mt-4 flex items-center gap-3">
                        <button type="button" wire:click="enableVacation" wire:loading.attr="disabled" wire:target="enableVacation"
                                class="min-h-11 rounded-xl bg-amber-500 px-4 text-sm font-semibold text-slate-950 hover:bg-amber-400 transition disabled:opacity-50">
                            <span wire:loading.remove wire:target="enableVacation">Enable</span>
                            <span wire:loading wire:target="enableVacation">Enabling…</span>
                        </button>
                        <button type="button" wire:click="cancelEnable"
                                class="min-h-11 rounded-xl border border-white/[0.10] px-4 text-sm font-semibold text-slate-300 hover:bg-white/[0.05] transition">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        @elseif ($status === \App\Enums\InstructorStatus::Vacation)
            <div class="flex items-center gap-2 mb-1">
                <span class="h-2.5 w-2.5 rounded-full bg-amber-400" aria-hidden="true"></span>
                <h2 class="text-lg font-semibold text-white">Vacation Mode Active</h2>
            </div>
            <p class="mt-2 text-sm leading-6 text-slate-400">You are temporarily unavailable for new lesson bookings.</p>
            @if ($statusChangedAt)
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Started: <span class="text-slate-300 normal-case">{{ $statusChangedAt->format('j M Y') }}</span></p>
            @endif

            @if (! $confirmingResume)
                <button type="button" wire:click="confirmResume"
                        class="mt-5 min-h-11 inline-flex items-center rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-500 transition">
                    Resume Teaching
                </button>
            @else
                <div class="mt-5 rounded-xl border border-emerald-400/20 bg-emerald-500/10 p-4">
                    <p class="text-sm font-semibold text-white">Resume accepting new bookings?</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">Your previous availability schedule will become active again.</p>
                    <div class="mt-4 flex items-center gap-3">
                        <button type="button" wire:click="resumeTeaching" wire:loading.attr="disabled" wire:target="resumeTeaching"
                                class="min-h-11 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-500 transition disabled:opacity-50">
                            <span wire:loading.remove wire:target="resumeTeaching">Resume</span>
                            <span wire:loading wire:target="resumeTeaching">Resuming…</span>
                        </button>
                        <button type="button" wire:click="cancelResume"
                                class="min-h-11 rounded-xl border border-white/[0.10] px-4 text-sm font-semibold text-slate-300 hover:bg-white/[0.05] transition">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        @else
            <div class="flex items-center gap-2 mb-1">
                <span class="h-2.5 w-2.5 rounded-full bg-rose-400" aria-hidden="true"></span>
                <h2 class="text-lg font-semibold text-white">Vacation mode unavailable</h2>
            </div>
            <p class="mt-2 text-sm leading-6 text-slate-400">Your account is currently restricted. Contact support.</p>
        @endif
    </x-account.card>
</div>
