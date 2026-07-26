<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Models\FailedJob;
use App\Queue\DTOs\FailedJobRetryResult;
use App\Queue\Enums\FailedJobRetryOutcome;
use App\Queue\Services\FailedJobRetryService;
use App\Queue\Support\FailedJobPayloadSummary;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class QueueMonitorPage extends Page implements HasTable
{
    use HasCentralizedNavigation;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Queue Monitor';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'system/queue-monitor';

    protected string $view = 'filament.pages.queue-monitor';

    // ── Authorization ─────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return method_exists($user, 'hasPermissionTo')
                && $user->hasPermissionTo('queue_monitor.view');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private function canRetry(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('queue_monitor.retry_failed_jobs');
    }

    // ── Page metadata ──────────────────────────────────────────────────────

    public function getTitle(): string|Htmlable
    {
        return 'Queue Monitor';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Queue driver status, pending jobs, and failed job history.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/system/queue-monitor' => 'System',
            '#' => 'Queue Monitor',
        ];
    }

    // ── Failed-jobs table (Phase 24N — GAP-034) ─────────────────────────────

    /**
     * Whether the `database-uuids`/`database` failed-job driver is
     * active — independent of `queue.default` (the connection new jobs
     * dispatch to). A failure logged while any connection was active
     * still lands in this same table, so the failed-jobs surface must
     * not hide behind the CURRENT default connection.
     */
    private function usesDatabaseFailedJobDriver(): bool
    {
        return in_array(config('queue.failed.driver'), ['database-uuids', 'database'], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->usesDatabaseFailedJobDriver() ? FailedJob::query() : FailedJob::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('uuid')
                    ->label('Reference')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 8).'…')
                    ->copyable()
                    ->copyableState(fn (string $state): string => $state),
                TextColumn::make('payload')
                    ->label('Job')
                    ->formatStateUsing(fn (string $state): string => FailedJobPayloadSummary::fromRawPayload($state)->displayName ?? '(unknown job class)'),
                TextColumn::make('connection')
                    ->badge(),
                TextColumn::make('queue')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('failed_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                TextColumn::make('exception')
                    ->label('Exception')
                    ->formatStateUsing(fn (string $state): string => FailedJobPayloadSummary::exceptionSummary($state))
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('failed_at', 'desc')
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('The job will be placed back on its original queue. This does not guarantee it will succeed. Retrying may repeat the job\'s operation — confirm the underlying issue has been resolved before continuing.')
                    ->authorize(fn (): bool => $this->canRetry())
                    ->action(fn (FailedJob $record) => $this->handleRetry($record->uuid)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retry_selected')
                        ->label('Retry selected')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Selected jobs will be placed back on their original queues. This does not guarantee they will succeed. Retrying may repeat a job\'s operation — confirm the underlying issue has been resolved before continuing.')
                        ->authorize(fn (): bool => $this->canRetry())
                        ->action(fn (Collection $records) => $this->handleBulkRetry($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('The failed_jobs table is empty.');
    }

    private function handleRetry(string $uuid): void
    {
        $result = app(FailedJobRetryService::class)->retry(auth()->user(), $uuid);

        $this->notifyRetryResult($result);
    }

    /** @param  Collection<int, FailedJob>  $records */
    private function handleBulkRetry(Collection $records): void
    {
        $service = app(FailedJobRetryService::class);
        $actor = auth()->user();

        $counts = [
            FailedJobRetryOutcome::Retried->value => 0,
            FailedJobRetryOutcome::NotFound->value => 0,
            FailedJobRetryOutcome::UnsupportedConnection->value => 0,
            FailedJobRetryOutcome::EnqueueFailed->value => 0,
        ];

        // Bounded by the admin's own selection — never an unbounded
        // "retry every failed job" sweep. One job's failure never hides
        // another's result — each is processed and tallied independently.
        foreach ($records as $record) {
            $result = $service->retry($actor, $record->uuid);
            $counts[$result->outcome->value]++;
        }

        Notification::make()
            ->title('Bulk retry complete')
            ->body(sprintf(
                'Retried: %d · Already retried/not found: %d · Unsupported: %d · Failed: %d',
                $counts[FailedJobRetryOutcome::Retried->value],
                $counts[FailedJobRetryOutcome::NotFound->value],
                $counts[FailedJobRetryOutcome::UnsupportedConnection->value],
                $counts[FailedJobRetryOutcome::EnqueueFailed->value],
            ))
            ->{$counts[FailedJobRetryOutcome::Retried->value] > 0 ? 'success' : 'warning'}()
            ->send();
    }

    private function notifyRetryResult(FailedJobRetryResult $result): void
    {
        Notification::make()
            ->title($result->outcome->isSuccess() ? 'Job re-queued' : 'Retry did not complete')
            ->body($result->message)
            ->{$result->outcome->isSuccess() ? 'success' : 'warning'}()
            ->send();
    }

    // ── Data (existing summary sections, unchanged) ─────────────────────────

    public function getQueueInfo(): array
    {
        $driver = config('queue.default', 'sync');
        $connection = config("queue.connections.{$driver}", []);

        return [
            'driver' => strtoupper($driver),
            'connection' => $connection['driver'] ?? $driver,
            'table' => $connection['table'] ?? ($driver === 'database' ? 'jobs' : null),
        ];
    }

    /** @return array<array{queue: string, pending: int, oldest_age: string|null}> */
    public function getQueueDepths(): array
    {
        $driver = config('queue.default', 'sync');

        if ($driver !== 'database') {
            return [];
        }

        $rows = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as pending'), DB::raw('MIN(created_at) as oldest'))
            ->groupBy('queue')
            ->orderByDesc('pending')
            ->get();

        return $rows->map(function ($row) {
            $oldest = $row->oldest ? Carbon::createFromTimestamp($row->oldest) : null;
            $ageMinutes = $oldest ? (int) $oldest->diffInMinutes(now()) : null;

            return [
                'queue' => $row->queue,
                'pending' => (int) $row->pending,
                'oldest_age' => $ageMinutes !== null ? $this->formatAge($ageMinutes) : null,
                'stalled' => $ageMinutes !== null && $ageMinutes > 5,
            ];
        })->all();
    }

    public function getFailedJobStats(): array
    {
        if (! $this->usesDatabaseFailedJobDriver()) {
            return ['count' => 0, 'byQueue' => []];
        }

        $count = DB::table('failed_jobs')->count();

        $byQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('COUNT(*) as total'))
            ->groupBy('queue')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['queue' => $r->queue, 'count' => (int) $r->total])
            ->all();

        return compact('count', 'byQueue');
    }

    public function isWorkerLikelyRunning(): bool
    {
        $driver = config('queue.default', 'sync');

        if ($driver === 'sync') {
            return true;
        }

        if ($driver !== 'database') {
            return false;
        }

        // If there are jobs older than 5 minutes still pending, the worker is likely stalled/down.
        $stalledCount = DB::table('jobs')
            ->where('created_at', '<', now()->subMinutes(5)->timestamp)
            ->whereNull('reserved_at')
            ->count();

        // A stalled queue does not definitively mean the worker is down (rate-limited or slow job),
        // but it is the best heuristic available without a heartbeat table.
        return $stalledCount === 0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function formatAge(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return $rem > 0 ? "{$hours}h {$rem}m" : "{$hours}h";
    }
}
