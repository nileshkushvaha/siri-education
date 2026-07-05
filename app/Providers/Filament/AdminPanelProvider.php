<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\AdminChangePassword;
use App\Filament\Pages\AdminProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\CacheManagerPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\QueueMonitorPage;
use App\Filament\Pages\SchedulerMonitorPage;
use App\Filament\Widgets\RecentAuditTrailWidget;
use App\Filament\Widgets\RecentLoginsWidget;
use App\Filament\Widgets\RecentUsersWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Http\Middleware\EnsurePasswordChangeRequired;
use App\Settings\GeneralSettings;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->brandName('Sphere Education')
            ->brandLogo(null)
            ->favicon(fn (): ?string => $this->faviconUrl())

            // ── Profile page: adds "My Profile" to user menu automatically ──
            ->profile(AdminProfile::class, isSimple: false)

            // ── Extra user menu items ─────────────────────────────────────────
            ->userMenuItems([
                Action::make('change_password')
                    ->label('Change Password')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->url(fn () => AdminChangePassword::getUrl())
                    ->sort(1),
            ])

            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->navigationGroups([
                'Platform',
                'Users & Access',
                'Academic',
                'Marketplace',
                'Scheduling',
                'Booking',
                'Finance',
                'Content',
                'Communication',
                'Reports',
                'System',
            ])
            ->pages([
                Dashboard::class,
                CacheManagerPage::class,
                SchedulerMonitorPage::class,
                QueueMonitorPage::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                RecentUsersWidget::class,
                RecentLoginsWidget::class,
                RecentAuditTrailWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChangeRequired::class,
            ]);
    }

    private function faviconUrl(): ?string
    {
        $path = app(GeneralSettings::class)->favicon ?? null;

        if (blank($path)) {
            return null;
        }

        return str_starts_with($path, 'http') || str_starts_with($path, '//')
            ? $path
            : Storage::disk('public')->url($path);
    }
}
