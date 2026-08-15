<div class="space-y-6"
    x-data="{
        copied: null,
        canShare: typeof navigator !== 'undefined' && !! navigator.share,
        async copy(value, which) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const el = document.createElement('textarea');
                    el.value = value;
                    el.setAttribute('readonly', '');
                    el.style.position = 'fixed';
                    el.style.opacity = '0';
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                }
                this.copied = which;
                setTimeout(() => { if (this.copied === which) this.copied = null; }, 2000);
            } catch (e) {
                // Clipboard unavailable — the user can still select the text manually.
            }
        },
        share(title, text, url) {
            navigator.share({ title, text, url }).catch(() => {});
        },
    }">

    @if($codeDisabled)
        <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-200">
            Your referral code is currently disabled. Please contact support if you believe this is a mistake.
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Referral code --}}
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Your referral code</p>
                <div class="mt-3 flex items-center gap-3">
                    <p class="text-2xl font-bold tracking-widest text-white select-all" data-referral-code>{{ $code }}</p>
                    <button type="button" @click="copy(@js($code), 'code')"
                        class="px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-300 bg-white/[0.05] border border-white/[0.08] hover:bg-white/[0.1] transition">
                        <span x-show="copied !== 'code'">Copy code</span>
                        <span x-show="copied === 'code'" class="text-emerald-400">Copied!</span>
                    </button>
                </div>
            </x-account.card>

            {{-- Referral link --}}
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Your referral link</p>
                <div class="mt-3 flex items-center gap-3 min-w-0">
                    <p class="text-sm text-slate-300 truncate select-all" data-referral-link>{{ $link }}</p>
                    <button type="button" @click="copy(@js($link), 'link')"
                        class="flex-shrink-0 px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-300 bg-white/[0.05] border border-white/[0.08] hover:bg-white/[0.1] transition">
                        <span x-show="copied !== 'link'">Copy link</span>
                        <span x-show="copied === 'link'" class="text-emerald-400">Copied!</span>
                    </button>
                </div>
            </x-account.card>
        </div>

        {{-- Share actions --}}
        <x-account.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-3">Share with friends</p>
            <div class="flex flex-wrap items-center gap-3">
                <a href="https://wa.me/?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-emerald-300 bg-emerald-500/10 border border-emerald-500/25 hover:bg-emerald-500/20 transition">
                    WhatsApp
                </a>
                <a href="mailto:?subject={{ rawurlencode('Join me on ' . config('app.name')) }}&body={{ rawurlencode($shareText) }}"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-indigo-300 bg-indigo-500/10 border border-indigo-500/25 hover:bg-indigo-500/20 transition">
                    Email
                </a>
                <button type="button" x-show="canShare" x-cloak
                    @click="share(@js('Join me on ' . config('app.name')), @js($shareText), @js($link))"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-slate-300 bg-white/[0.05] border border-white/[0.08] hover:bg-white/[0.1] transition">
                    Share…
                </button>
            </div>
        </x-account.card>
    @endif

    {{-- How it works --}}
    <x-account.card>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-3">How it works</p>
        <ul class="space-y-2 text-sm text-slate-300">
            <li>Share your referral code or link with a friend.</li>
            <li>Your friend enters the code when creating their student account.</li>
            <li>Rewards are subject to active campaign terms and are earned only after your friend completes eligible paid lessons — registration alone does not guarantee a reward.</li>
        </ul>
    </x-account.card>

    {{-- Referral status — source-backed, currency-separated, masked --}}
    @if($summary['referred_students'] === 0 && $history->total() === 0)
        <x-account.card>
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No referral activity yet</h3>
                <p class="text-slate-400 text-sm max-w-sm">Referral tracking and rewards will appear here once eligible activity occurs.</p>
            </div>
        </x-account.card>
    @else
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Friends joined</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ $summary['referred_students'] }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pending rewards</p>
                <p class="mt-2 text-2xl font-bold text-sky-300">{{ $summary['eligible'] }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Under review</p>
                <p class="mt-2 text-2xl font-bold text-amber-300">{{ $summary['held'] }}</p>
            </x-account.card>
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Credited</p>
                @forelse($summary['credited_by_currency'] as $currency => $amountMinor)
                    <p class="mt-2 text-2xl font-bold text-emerald-400">{{ \App\Support\MoneyFormatter::format($amountMinor, $currency) }}</p>
                @empty
                    <p class="mt-2 text-2xl font-bold text-slate-500">—</p>
                @endforelse
            </x-account.card>
        </div>

        @if($summary['reversed_by_currency'] !== [])
            <div class="rounded-2xl border border-amber-400/30 bg-amber-500/10 p-4 text-sm text-amber-200">
                Reversed rewards:
                @foreach($summary['reversed_by_currency'] as $currency => $amountMinor)
                    <strong>{{ \App\Support\MoneyFormatter::format($amountMinor, $currency) }}</strong>@if(! $loop->last), @endif
                @endforeach
            </div>
        @endif

        <x-account.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-3">Reward history</p>
            @forelse($history as $reward)
                <div wire:key="reward-{{ $reward->id }}" class="flex items-center justify-between py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ \App\Referral\Support\ReferredStudentMask::mask($reward->referredStudent) }}</p>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-white/[0.06] text-slate-300">{{ $reward->status->label() }}</span>
                        </div>
                        <p class="text-xs text-slate-400">
                            Class #{{ $reward->class_sequence }}
                            &middot; Eligible {{ viewer_date($reward->eligible_at) }}
                            @if($reward->credited_at)
                                &middot; Credited {{ viewer_date($reward->credited_at) }}
                            @endif
                            @if($reward->reversed_at)
                                &middot; Reversed {{ viewer_date($reward->reversed_at) }}
                            @endif
                        </p>
                    </div>
                    <p class="text-sm font-semibold text-white flex-shrink-0 ml-3">
                        {{ \App\Support\MoneyFormatter::format($reward->reward_amount_minor, $reward->reward_currency_code) }}
                    </p>
                </div>
            @empty
                <p class="text-slate-400 text-sm py-6 text-center">No rewards yet — rewards appear after your friends complete eligible paid classes.</p>
            @endforelse

            @if($history->hasPages())
                <div class="mt-4">
                    {{ $history->links() }}
                </div>
            @endif
        </x-account.card>
    @endif
</div>
