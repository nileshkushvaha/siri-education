<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Services\CacheManagerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class CacheManagerPage extends Page
{
    use HasCentralizedNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Cache Manager';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'system/cache-manager';

    protected string $view = 'filament.pages.cache-manager';

    // ── State ─────────────────────────────────────────────────────────────

    /** @var array<string, mixed>|null */
    public ?array $lastResult = null;

    public bool $processing = false;

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
            return method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('cache_manager.view');
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
        return 'Cache Manager';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Monitor cache status and safely manage application caches.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/system/cache-manager' => 'System',
            '#' => 'Cache Manager',
        ];
    }

    // ── System info ───────────────────────────────────────────────────────

    public function getCacheInfo(): array
    {
        /** @var CacheManagerService $service */
        $service = app(CacheManagerService::class);

        return [
            [
                'label' => 'Cache Driver',
                'value' => $service->getCacheDriver(),
                'icon' => 'heroicon-o-circle-stack',
                'group' => 'infrastructure',
            ],
            [
                'label' => 'Cache Store',
                'value' => $service->getCacheStore(),
                'icon' => 'heroicon-o-server-stack',
                'group' => 'infrastructure',
            ],
            [
                'label' => 'Environment',
                'value' => ucfirst($service->getEnvironment()),
                'icon' => 'heroicon-o-globe-alt',
                'group' => 'infrastructure',
            ],
            [
                'label' => 'Laravel Version',
                'value' => 'v'.$service->getLaravelVersion(),
                'icon' => 'heroicon-o-code-bracket',
                'group' => 'infrastructure',
            ],
            [
                'label' => 'PHP Version',
                'value' => 'PHP '.$service->getPhpVersion(),
                'icon' => 'heroicon-o-cpu-chip',
                'group' => 'infrastructure',
            ],
            [
                'label' => 'Config Cache',
                'value' => $service->isConfigCached() ? 'Cached' : 'Not cached',
                'cached' => $service->isConfigCached(),
                'icon' => 'heroicon-o-cog-6-tooth',
                'group' => 'cache',
            ],
            [
                'label' => 'Route Cache',
                'value' => $service->isRouteCached() ? 'Cached' : 'Not cached',
                'cached' => $service->isRouteCached(),
                'icon' => 'heroicon-o-map',
                'group' => 'cache',
            ],
            [
                'label' => 'View Cache',
                'value' => $service->isViewCached() ? 'Cached' : 'Not cached',
                'cached' => $service->isViewCached(),
                'icon' => 'heroicon-o-eye',
                'group' => 'cache',
            ],
            [
                'label' => 'Event Cache',
                'value' => $service->isEventCached() ? 'Cached' : 'Not cached',
                'cached' => $service->isEventCached(),
                'icon' => 'heroicon-o-bolt',
                'group' => 'cache',
            ],
        ];
    }

    // ── Header actions (condensed into groups) ────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->makeAction('clear_app_cache', 'Clear Cache Store', 'clearApplicationCache', 'cache:clear', 'heroicon-o-trash', 'danger', 'cache_manager.clear'),
                $this->makeAction('clear_view_cache', 'Clear View Cache', 'clearViewCache', 'view:clear', 'heroicon-o-eye-slash', 'warning', 'cache_manager.clear'),
                $this->makeAction('clear_route_cache', 'Clear Route Cache', 'clearRouteCache', 'route:clear', 'heroicon-o-map', 'warning', 'cache_manager.clear'),
                $this->makeAction('clear_config_cache', 'Clear Config Cache', 'clearConfigCache', 'config:clear', 'heroicon-o-cog-6-tooth', 'warning', 'cache_manager.clear'),
                $this->makeAction('clear_event_cache', 'Clear Event Cache', 'clearEventCache', 'event:clear', 'heroicon-o-bolt-slash', 'warning', 'cache_manager.clear'),
            ])
                ->label('Clear Caches')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->button(),

            ActionGroup::make([
                $this->makeAction('optimize', 'Optimize', 'optimize', 'optimize', 'heroicon-o-rocket-launch', 'success', 'cache_manager.optimize'),
                $this->makeAction('optimize_clear', 'Optimize Clear', 'optimizeClear', 'optimize:clear', 'heroicon-o-arrow-path', 'gray', 'cache_manager.optimize'),
            ])
                ->label('Optimize')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->button(),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function userHasPermission(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function makeAction(
        string $name,
        string $label,
        string $serviceMethod,
        string $artisanCommand,
        string $icon,
        string $color,
        string $permission,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->disabled(fn () => $this->processing || ! $this->userHasPermission($permission))
            ->requiresConfirmation()
            ->modalHeading("Confirm: {$label}")
            ->modalDescription("This will run artisan {$artisanCommand}. Are you sure you want to continue?")
            ->modalSubmitActionLabel('Yes, proceed')
            ->action(function () use ($serviceMethod) {
                $this->processing = true;

                try {
                    /** @var CacheManagerService $service */
                    $service = app(CacheManagerService::class);
                    $result = $service->{$serviceMethod}();
                    $this->lastResult = $result;

                    if ($result['success']) {
                        Notification::make()->title('Success')->body($result['message'])->success()->send();
                    } else {
                        Notification::make()->title('Command Failed')->body($result['output'])->danger()->send();
                    }
                } catch (\Throwable $e) {
                    $this->lastResult = [
                        'success' => false,
                        'message' => 'An unexpected error occurred.',
                        'output' => $e->getMessage(),
                        'exitCode' => -1,
                        'timestamp' => now()->toDateTimeString(),
                    ];

                    Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                } finally {
                    $this->processing = false;
                }
            });
    }
}
