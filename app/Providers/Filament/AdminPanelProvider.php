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
use App\Http\Middleware\EnsurePasswordChangeRequired;
use App\Http\Middleware\TrackUserSession;
use App\Settings\GeneralSettings;
use App\Support\Timezone\ViewerDateTime;
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
use Filament\Support\Facades\FilamentTimezone;
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

        // TZ-4 (TZ-AUD-007 / TZ-AUD-009): ONE registration gives every
        // Filament date-TIME column and every DateTimePicker the
        // logged-in admin's own clock. Before this, all 169 dateTime()
        // columns rendered UTC while both portals rendered local, and an
        // admin typing "09:00" into a reschedule picker had it stored as
        // 09:00 UTC.
        //
        // A Closure, not a string: the panel boots long before anyone is
        // authenticated, and TimezoneManager re-evaluates on every get(),
        // so this resolves per request and per user rather than being
        // frozen at boot.
        //
        // Filament applies this ONLY to values that carry a time — see
        // CanFormatState::getTimezone() and DateTimePicker::getTimezone(),
        // both of which fall back to config('app.timezone') when the
        // component has no time component. Date-only columns and
        // DatePickers (birthdays, holiday dates, compensation period
        // boundaries) are therefore untouched, which is exactly right:
        // shifting a date-only value by an offset is how a birthday moves
        // a day.
        //
        // The picker round-trip is Filament's own DateTimeStateCast:
        // reading converts the stored UTC value INTO this timezone, and
        // writing shiftTimezone()s the typed wall clock out of it back to
        // app timezone. That is why action code parsing the dehydrated
        // value as UTC (BookingsTable, BuildsCampaignData, …) is correct
        // and must stay — the conversion has already happened, and a
        // second one would double-shift.
        //
        // Deliberately NOT applied to date FILTERS: "which rows fall on
        // 15 August" is a local-day query-boundary question with
        // financial consequences, and it belongs to TZ-5.
        FilamentTimezone::set(static fn (): string => ViewerDateTime::timezoneFor());
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
            ->brandName(fn (): string => $this->brandName())
            ->brandLogo(null)
            ->favicon(fn (): string => $this->faviconUrl())
            // Filament defaults every page's content to max-w-7xl (1280px)
            // when unset — on wide monitors that leaves a large dead zone
            // next to every form/table. Use the full available width instead.
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()

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
            // Day-to-day domain work first, then administration. The last four
            // are deliberately separate concerns that all used to be dumped
            // into "Settings", which made it a 22-item list mixing master data
            // (Countries), identity (Roles), health monitoring (Queue Monitor)
            // and actual configuration:
            //   Reference Data — CRUD master data other records depend on
            //   Access Control — who may sign in and what they may do
            //   System         — is the platform healthy, and what happened
            //   Settings       — platform-wide configuration only
            // Domain-specific configuration (Mail, payments, SEO…) stays with
            // the domain it configures rather than moving here.
            ->navigationGroups([
                'People',
                'Academics',
                'Operations',
                'Finance',
                'Growth',
                'Content & Communication',
                'Quality & Compliance',
                'Analytics',
                'Reference Data',
                'Access Control',
                'System',
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
                    // System, not Settings: Pulse reports platform health, it
                    // configures nothing — same reasoning as Queue Monitor,
                    // Scheduler and Cache Manager.
                    ->group('System')
                    ->sort(7)
                    ->url(fn (): string => '/'.config('pulse.path', 'pulse'))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => auth()->user()?->can('viewPulse') ?? false),
            ])
            // Discovery registers every widget as a Livewire component so
            // pages can render one explicitly. No widget is listed in
            // ->widgets() any more: the dashboard composes its own layout
            // in Blade instead of rendering a generic widget grid, and
            // the panel-level list only ever fed that grid.
            //
            // StatsOverviewWidget, RecentUsersWidget, RecentLoginsWidget
            // and RecentAuditTrailWidget remain intact and permissioned
            // for reuse elsewhere — they are simply no longer part of the
            // dashboard, which now shows marketplace figures rather than
            // identity-system activity tables.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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

    private function brandName(): string
    {
        return app(GeneralSettings::class)->app_name ?: config('app.name');
    }

    /**
     * Never null. Returning null left Filament with no favicon at all, and the
     * usual last-resort fallback does not help here either — public/favicon.ico
     * is a 0-byte placeholder, so the browser had nothing to fall back to and
     * showed its generic page icon.
     */
    private function faviconUrl(): string
    {
        $path = app(GeneralSettings::class)->favicon ?? null;

        if (filled($path)) {
            return str_starts_with($path, 'http') || str_starts_with($path, '//')
                ? $path
                : Storage::disk('public')->url($path);
        }

        return $this->defaultFaviconDataUri();
    }

    /**
     * A brand-lettered mark generated from the configured application name,
     * inlined as a data URI.
     *
     * Generated rather than shipped as a file so it always tracks the name in
     * Admin -> Settings -> General, and so no deployment step is needed before
     * the panel has an icon. An uploaded favicon always wins over this.
     */
    private function defaultFaviconDataUri(): string
    {
        $letter = mb_strtoupper(mb_substr(trim($this->brandName()), 0, 1));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="14" fill="#4f46e5"/>'
            .'<text x="32" y="45" text-anchor="middle" fill="#ffffff"'
            .' font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif"'
            .' font-size="38" font-weight="700">'
            .htmlspecialchars($letter !== '' ? $letter : 'A', ENT_QUOTES | ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
