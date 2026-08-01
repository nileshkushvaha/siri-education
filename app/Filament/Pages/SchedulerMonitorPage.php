<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Services\SchedulerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SchedulerMonitorPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Scheduler';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'system/scheduler';

    protected string $view = 'filament.pages.scheduler-monitor';

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
                && $user->hasPermissionTo('scheduler_monitor.view');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    // ── Page metadata ──────────────────────────────────────────────────────

    public function getTitle(): string|Htmlable
    {
        return 'Scheduler Monitor';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'View scheduled tasks, execution history, and run tasks manually.';
    }

    // ── Data ──────────────────────────────────────────────────────────────

    public function getTasks(): Collection
    {
        return app(SchedulerService::class)->getTasks();
    }

    public function getRecentHistory(): Collection
    {
        return app(SchedulerService::class)->getRecentHistory(20);
    }

    public function canRunTasks(): bool
    {
        return auth()->check() && Gate::allows('scheduler_monitor.run');
    }

    // ── Actions ───────────────────────────────────────────────────────────

    public function runNowAction(): Action
    {
        return Action::make('runNow')
            ->label('Run Now')
            ->icon('heroicon-o-play')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Run task: '.($arguments['name'] ?? 'task'))
            ->modalDescription('This will execute the task immediately, outside the normal schedule. The page will refresh with the result.')
            ->modalSubmitActionLabel('Run Now')
            ->action(function (array $arguments): void {
                if (! $this->canRunTasks()) {
                    Notification::make()->title('Unauthorised')->danger()->send();

                    return;
                }

                try {
                    $result = app(SchedulerService::class)->runNow($arguments['id']);

                    if ($result['status'] === 'success') {
                        Notification::make()
                            ->title('Task completed')
                            ->body('Finished in '.$this->formatDuration($result['duration_ms']))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Task failed')
                            ->body($result['output'] ?: 'The task exited with a non-zero status.')
                            ->danger()
                            ->send();
                    }
                } catch (\Throwable $e) {
                    Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                }
            });
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function formatDuration(int $ms): string
    {
        return $ms < 1000 ? "{$ms}ms" : round($ms / 1000, 2).'s';
    }
}
