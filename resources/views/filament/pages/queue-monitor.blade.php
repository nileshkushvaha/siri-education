<x-filament-panels::page>

    @php
        $info       = $this->getQueueInfo();
        $depths     = $this->getQueueDepths();
        $failed     = $this->getFailedJobStats();
        $workerUp   = $this->isWorkerLikelyRunning();
        $totalPending = collect($depths)->sum('pending');
    @endphp

    <div class="space-y-6">

        {{-- ── Section 1: Infrastructure + Worker Status ───────────────────── --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            {{-- Infrastructure --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <x-filament::icon icon="heroicon-o-server-stack" class="h-5 w-5 text-gray-400" />
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Infrastructure</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    <div class="flex items-center justify-between px-6 py-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Queue Driver</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $info['driver'] }}</span>
                    </div>
                    <div class="flex items-center justify-between px-6 py-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Connection</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $info['connection'] }}</span>
                    </div>
                    @if($info['table'])
                        <div class="flex items-center justify-between px-6 py-3">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Jobs Table</span>
                            <code class="text-xs font-mono text-gray-950 dark:text-white">{{ $info['table'] }}</code>
                        </div>
                    @endif
                    <div class="flex items-center justify-between px-6 py-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Total Pending</span>
                        <span class="text-sm font-medium {{ $totalPending > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
                            {{ number_format($totalPending) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between px-6 py-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Failed Jobs</span>
                        <span class="text-sm font-medium {{ $failed['count'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">
                            {{ number_format($failed['count']) }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Worker Status --}}
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <x-filament::icon icon="heroicon-o-cpu-chip" class="h-5 w-5 text-gray-400" />
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Worker Status</h3>
                </div>
                <div class="flex flex-col items-center justify-center py-8 gap-3">
                    @if(config('queue.default') === 'sync')
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 px-3 py-1.5 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                            <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                            Sync driver — no worker needed
                        </span>
                        <p class="text-xs text-gray-400 dark:text-gray-500 text-center max-w-xs">Jobs run synchronously in the request cycle. Switch to <code class="font-mono">database</code> or <code class="font-mono">redis</code> for async processing.</p>
                    @elseif($workerUp)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-3 py-1.5 text-sm font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20">
                            <span class="h-2 w-2 rounded-full bg-success-500 animate-pulse"></span>
                            Worker likely running
                        </span>
                        <p class="text-xs text-gray-400 dark:text-gray-500 text-center max-w-xs">No jobs older than 5 minutes are waiting. Worker appears healthy.</p>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-3 py-1.5 text-sm font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/20">
                            <span class="h-2 w-2 rounded-full bg-danger-500"></span>
                            Worker may be stalled
                        </span>
                        <p class="text-xs text-gray-400 dark:text-gray-500 text-center max-w-xs">Jobs older than 5 minutes are still pending. Run <code class="font-mono">php artisan queue:work</code> to process them.</p>
                    @endif
                </div>
                <div class="border-t border-gray-100 dark:border-white/5 px-6 py-3">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Worker health is inferred from job queue age — no heartbeat table is used.
                        For production monitoring, install <a href="https://laravel.com/docs/horizon" class="underline hover:text-gray-600 dark:hover:text-gray-300" target="_blank" rel="noopener">Laravel Horizon</a>.
                    </p>
                </div>
            </div>

        </div>

        {{-- ── Section 2: Queue Depths ──────────────────────────────────────── --}}
        @if(config('queue.default') === 'database')
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <x-filament::icon icon="heroicon-o-queue-list" class="h-5 w-5 text-gray-400" />
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Queue Depths</h3>
                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">Pending jobs by named queue</span>
                </div>

                @if(empty($depths))
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-success-400 mb-2" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">All queues are empty</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">No pending jobs found in the <code class="font-mono">jobs</code> table.</p>
                    </div>
                @else
                    <div class="hidden lg:grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-x-4 px-6 py-3 border-b border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/[0.02]">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Queue</span>
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Pending</span>
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Oldest Job</span>
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Status</span>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($depths as $row)
                            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-x-4 gap-y-2 px-6 py-4 items-center">
                                <code class="text-sm font-mono font-semibold text-primary-600 dark:text-primary-400">{{ $row['queue'] }}</code>
                                <span class="text-sm font-medium {{ $row['pending'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ number_format($row['pending']) }}
                                </span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $row['oldest_age'] ?? '—' }}
                                </span>
                                @if($row['stalled'])
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-danger-500"></span>
                                        Stalled
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                        Processing
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Section 3: Failed Jobs ───────────────────────────────────────── --}}
        {{-- Gated on the failed-job driver (independent of queue.default —
             a failure is logged here regardless of which connection dispatched it). --}}
        @if(in_array(config('queue.failed.driver'), ['database-uuids', 'database'], true))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 {{ $failed['count'] > 0 ? 'text-danger-400' : 'text-gray-400' }}" />
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Failed Jobs</h3>
                    @if($failed['count'] > 0)
                        <span class="ml-auto inline-flex items-center rounded-full bg-danger-50 px-2.5 py-0.5 text-xs font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/20">
                            {{ number_format($failed['count']) }} total
                        </span>
                    @endif
                </div>

                @if($failed['count'] === 0)
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-success-400 mb-2" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No failed jobs</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">The <code class="font-mono">failed_jobs</code> table is empty.</p>
                    </div>
                @else
                    {{-- By Queue breakdown --}}
                    @if(count($failed['byQueue']) > 1)
                        <div class="px-6 py-4 border-b border-gray-100 dark:border-white/5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-3">Failures by queue</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($failed['byQueue'] as $bq)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/20">
                                        <code class="font-mono">{{ $bq['queue'] }}</code>
                                        <span class="font-bold">{{ $bq['count'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Phase 24N — GAP-034: the full, paginated, retryable failed-jobs table. --}}
                    <p class="px-6 pt-4 text-xs text-gray-400 dark:text-gray-500">
                        Retrying may repeat the job's operation. Confirm that the underlying issue has been resolved before retrying.
                    </p>
                    {{ $this->table }}
                @endif
            </div>
        @endif

    </div>

</x-filament-panels::page>
