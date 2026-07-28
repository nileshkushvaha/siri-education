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
use App\Http\Middleware\TrackUserSession;
use App\Settings\GeneralSettings;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\FontProviders\SpatieGoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Removes "Create & create another" from every conventional resource
     * create page in one place, via Filament's own supported extension
     * point (`CreateRecord::disableCreateAnother()` flips the protected
     * static `$canCreateAnother` property, which every subclass reads
     * through late static binding unless it redeclares the property
     * itself). No page override, macro, CSS, or JS is involved, and no
     * vendor file is touched.
     *
     * A resource that genuinely needs repeated-entry can opt back in by
     * declaring `protected static bool $canCreateAnother = true;` on its
     * own Create page — the same framework mechanism, used in reverse.
     */
    public function boot(): void
    {
        CreateRecord::disableCreateAnother();
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            // Self-hosts the panel's Inter font instead of loading it from
            // Google's CDN (spatie/laravel-google-fonts fetches + caches it).
            ->font('Inter', provider: SpatieGoogleFontProvider::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->brandName('Sphere Education')
            ->brandLogo(null)
            ->favicon(fn (): ?string => $this->faviconUrl())
            // Filament defaults every page's content to max-w-7xl (1280px)
            // when unset — on wide monitors that leaves a large dead zone
            // next to every form/table. Use the full available width instead.
            ->maxContentWidth(Width::Full)

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
            // See App\Filament\Navigation\NavigationRegistry for the
            // per-destination group/label/sort source of truth. Ten
            // primary sections (plus the ungrouped Dashboard/Home
            // link); order here is the sidebar's group display order.
            ->navigationGroups([
                'People',
                'Academics',
                'Operations',
                'Finance',
                'Growth',
                'Content & Communication',
                'Quality & Compliance',
                'Analytics',
                'Settings',
            ])
            ->pages([
                Dashboard::class,
                CacheManagerPage::class,
                SchedulerMonitorPage::class,
                QueueMonitorPage::class,
            ])
            // Pulse's dashboard is the package's
            // own route (never rewritten as a Filament page) — this is
            // a plain link, gated by the SAME 'viewPulse' Gate ability
            // Pulse's own Authorize middleware enforces on the route
            // itself, so a hidden-but-reachable link can never be the
            // only protection.
            ->navigationItems([
                NavigationItem::make('Application Performance')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->group('Settings')
                    ->sort(21)
                    ->url(fn (): string => '/'.config('pulse.path', 'pulse'))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => auth()->user()?->can('viewPulse') ?? false),
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
                // Shows a colored border + badge on non-production environments
                // so admins never mistake staging for production. Defaults to
                // visible only to super_admin (Spatie permissions detected).
                EnvironmentIndicatorPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChangeRequired::class,
                // SRS-1-23: the Filament panel defines
                // its own isolated middleware stack (never the app's
                // 'web' group), so idle-timeout enforcement must be
                // added here explicitly too.
                TrackUserSession::class,
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
