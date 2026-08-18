{{--
    Saved AI platform state. Reads the STORED settings, never the live
    form, and says so — the previous version showed a plain text dump
    that could silently disagree with an unsaved toggle sitting inches
    above it.
--}}
<div class="space-y-4">

    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="$status['module']['color']" size="lg">
            {{ $status['module']['label'] }}
        </x-filament::badge>
        <x-filament::badge :color="$status['provider']['color']">
            {{ $status['provider']['label'] }}
        </x-filament::badge>
        <x-filament::badge :color="$status['credential']['color']">
            {{ $status['credential']['label'] }}
        </x-filament::badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            Saved configuration &middot; unsaved edits below are not reflected here
        </span>
    </div>

    @if($status['warnings'] !== [])
        <div class="rounded-lg border border-amber-400/30 bg-amber-500/[0.06] px-4 py-3">
            <p class="text-xs font-semibold text-amber-700 dark:text-amber-300">Needs attention</p>
            <ul class="mt-1.5 space-y-1">
                @foreach($status['warnings'] as $warning)
                    <li class="text-xs text-amber-700 dark:text-amber-200/90">&bull; {{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

        @foreach($status['budgets'] as $budget)
            <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $budget['label'] }}</p>
                <p class="mt-0.5 text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $budget['spent'] }} {{ $status['currency'] }}
                    <span class="font-normal text-gray-500 dark:text-gray-400">
                        {{ $budget['limit'] === null ? '· no limit' : '· of '.$budget['limit'] }}
                    </span>
                </p>
                @if($budget['ratio'] !== null)
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                        <div class="h-full rounded-full {{ $budget['barClass'] }}" style="width: {{ min(100, (int) round($budget['ratio'] * 100)) }}%"></div>
                    </div>
                    <p class="mt-1 text-xs {{ $budget['textClass'] }}">{{ (int) round($budget['ratio'] * 100) }}% used</p>
                @endif
            </div>
        @endforeach

        <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
            <p class="text-xs text-gray-500 dark:text-gray-400">Runs today</p>
            <p class="mt-0.5 text-sm font-semibold text-gray-950 dark:text-white">{{ $status['runsToday'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">across all features</p>
        </div>

        <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
            <p class="text-xs text-gray-500 dark:text-gray-400">Last connection test</p>
            <p class="mt-0.5 text-sm font-semibold {{ $status['health']['class'] }}">{{ $status['health']['label'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $status['health']['when'] }}</p>
        </div>
    </div>

    <div>
        <p class="text-xs text-gray-500 dark:text-gray-400">Capabilities enabled</p>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
            @forelse($status['capabilities'] as $capability)
                <x-filament::badge :color="$capability['color']" size="sm">{{ $capability['label'] }}</x-filament::badge>
            @empty
                <span class="text-xs text-gray-500 dark:text-gray-400">None — no AI feature can run.</span>
            @endforelse
        </div>
    </div>
</div>
