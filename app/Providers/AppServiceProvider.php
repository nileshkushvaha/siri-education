<?php

namespace App\Providers;

use App\Contracts\InstructorEligibilityServiceInterface;
use App\Contracts\PhoneOtpSender;
use App\Contracts\PhoneVerificationServiceInterface;
use App\Contracts\StudentFinancialVerificationGate;
use App\Models\Activity;
use App\Models\EmailLog;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\LearningPlanAssessment;
use App\Models\LearningPlanMilestone;
use App\Models\LearningPlanReview;
use App\Models\NavigationMenu;
use App\Models\Page;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\SchedulerHistory;
use App\Models\StudentFavoriteInstructor;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use App\Models\UserProfile;
use App\Observers\ActivityObserver;
use App\Observers\FaqCategoryObserver;
use App\Observers\FaqObserver;
use App\Observers\PageObserver;
use App\Observers\PostCategoryObserver;
use App\Observers\PostObserver;
use App\Observers\TagObserver;
use App\Observers\UserEducationObserver;
use App\Observers\UserExperienceObserver;
use App\Observers\UserObserver;
use App\Observers\UserProfileObserver;
use App\Policies\ActivityLogPolicy;
use App\Policies\CacheManagerPolicy;
use App\Policies\EmailLogPolicy;
use App\Policies\FaqCategoryPolicy;
use App\Policies\FaqPolicy;
use App\Policies\InstructorDocumentPolicy;
use App\Policies\InstructorPolicy;
use App\Policies\LearningPlanAssessmentPolicy;
use App\Policies\LearningPlanMilestonePolicy;
use App\Policies\LearningPlanReviewPolicy;
use App\Policies\NavigationMenuPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ProfilePolicy;
use App\Policies\PulsePolicy;
use App\Policies\QueueMonitorPolicy;
use App\Policies\RolePolicy;
use App\Policies\SchedulerMonitorPolicy;
use App\Policies\Security\SecurityPolicy;
use App\Policies\StudentFavoriteInstructorPolicy;
use App\Policies\StudentLearningGoalPolicy;
use App\Policies\StudentLearningPlanPolicy;
use App\Policies\UserEducationPolicy;
use App\Policies\UserExperiencePolicy;
use App\Services\Instructor\InstructorEligibilityService;
use App\Services\Phone\PhoneVerificationService;
use App\Services\Phone\UnavailablePhoneOtpSender;
use App\Services\Student\DefaultStudentFinancialVerificationGate;
use App\Settings\LoginSecuritySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Services\WalletRechargeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Pulse as PulseManager;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PhoneOtpSender::class, UnavailablePhoneOtpSender::class);
        $this->app->bind(PhoneVerificationServiceInterface::class, PhoneVerificationService::class);
        $this->app->bind(StudentFinancialVerificationGate::class, DefaultStudentFinancialVerificationGate::class);
        $this->app->bind(InstructorEligibilityServiceInterface::class, InstructorEligibilityService::class);
        $this->app->bind(WalletRechargeServiceInterface::class, WalletRechargeService::class);
    }

    public function boot(): void
    {
        $this->registerSuperAdminGate();
        $this->registerPermissionObserver();
        $this->registerPolicies();
        $this->registerObservers();
        $this->registerSchedulerHistoryListeners();
        $this->registerRateLimiters();
        $this->guardAgainstDestructiveDatabaseCommands();
        $this->configurePulse();
    }

    /**
     * Phase 24O — GAP-033: Pulse's stock user resolver (Laravel\Pulse\Users)
     * returns the user's email in the "Usage" card's `extra` field by
     * default — overridden here to a display name only. Avoids touching
     * $user->profile (Pulse bulk-loads only the User model itself via
     * findMany(); resolving avatarUrl() would N+1-load a profile per
     * row) — no email, phone, billing, KYC, or financial data is ever
     * returned. A user Pulse cannot resolve (deleted/missing) never
     * reaches this callback at all — Laravel\Pulse\Users::find() only
     * invokes the field resolver when a user was actually found,
     * falling back to its own safe "ID: {key}" label otherwise.
     */
    private function configurePulse(): void
    {
        if (! $this->app->bound(PulseManager::class)) {
            return;
        }

        Pulse::user(fn (object $user): array => [
            'name' => filled($user->name ?? null) ? $user->name : sprintf('User #%s', $user->getAuthIdentifier()),
        ]);

        // Recorder failures must never break a real request/job — Pulse
        // already wraps every recorder in its own rescue(); this only
        // routes that swallowed exception through the app's normal,
        // already-redacting exception logger instead of being silently
        // discarded, so an operator can still see it happened.
        Pulse::handleExceptionsUsing(fn (Throwable $e) => report($e));
    }

    /**
     * Block migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback, and db:wipe
     * outside the testing environment, regardless of how they're triggered (CLI, IDE test
     * runner, RefreshDatabase, etc.) — enterprise_app is not recoverable.
     */
    private function guardAgainstDestructiveDatabaseCommands(): void
    {
        DB::prohibitDestructiveCommands(! $this->app->environment('testing'));
    }

    private function registerObservers(): void
    {
        Activity::observe(ActivityObserver::class);
        Faq::observe(FaqObserver::class);
        FaqCategory::observe(FaqCategoryObserver::class);
        Page::observe(PageObserver::class);
        Post::observe(PostObserver::class);
        PostCategory::observe(PostCategoryObserver::class);
        Tag::observe(TagObserver::class);
        User::observe(UserObserver::class);
        UserExperience::observe(UserExperienceObserver::class);
        UserEducation::observe(UserEducationObserver::class);
        UserProfile::observe(UserProfileObserver::class);
    }

    private function registerPolicies(): void
    {
        // App\Models\User is auto-discovered to App\Policies\UserPolicy by Laravel's
        // policy convention — do not bind it to ProfilePolicy here. ProfilePolicy only
        // implements view/update/changePassword (for the profile.* named gates below);
        // binding it as the model policy shadows UserPolicy's CRUD checks and made
        // Filament default-allow full User management (view/create/delete any account)
        // to every authenticated user, since Filament treats "no matching policy method"
        // as allowed rather than denied.
        Gate::policy(Faq::class, FaqPolicy::class);
        Gate::policy(FaqCategory::class, FaqCategoryPolicy::class);
        Gate::policy(NavigationMenu::class, NavigationMenuPolicy::class);
        Gate::policy(Activity::class, ActivityLogPolicy::class);
        Gate::policy(EmailLog::class, EmailLogPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(UserExperience::class, UserExperiencePolicy::class);
        Gate::policy(UserEducation::class, UserEducationPolicy::class);
        Gate::policy(StudentLearningGoal::class, StudentLearningGoalPolicy::class);
        Gate::policy(StudentFavoriteInstructor::class, StudentFavoriteInstructorPolicy::class);
        Gate::policy(StudentLearningPlan::class, StudentLearningPlanPolicy::class);
        Gate::policy(LearningPlanAssessment::class, LearningPlanAssessmentPolicy::class);
        Gate::policy(LearningPlanMilestone::class, LearningPlanMilestonePolicy::class);
        Gate::policy(LearningPlanReview::class, LearningPlanReviewPolicy::class);

        Gate::define('cache_manager.view', [CacheManagerPolicy::class, 'viewPage']);
        Gate::define('cache_manager.clear', [CacheManagerPolicy::class, 'clearApplicationCache']);
        Gate::define('cache_manager.optimize', [CacheManagerPolicy::class, 'optimize']);

        Gate::define('scheduler_monitor.view', [SchedulerMonitorPolicy::class, 'viewPage']);
        Gate::define('scheduler_monitor.run', [SchedulerMonitorPolicy::class, 'runTask']);

        Gate::define('queue_monitor.view', [QueueMonitorPolicy::class, 'viewPage']);
        Gate::define('queue_monitor.retry_failed_jobs', [QueueMonitorPolicy::class, 'retryFailedJobs']);

        // Phase 24O — GAP-033: 'viewPulse' is Laravel Pulse's own fixed
        // Gate ability name (its Authorize middleware calls exactly
        // this), overriding the package's local-environment-only default.
        Gate::define('viewPulse', [PulsePolicy::class, 'view']);

        Gate::define('instructor.viewAny', [InstructorPolicy::class, 'viewAny']);
        Gate::define('instructor.view', [InstructorPolicy::class, 'view']);
        Gate::define('instructor.viewDocuments', [InstructorDocumentPolicy::class, 'viewDocuments']);

        Gate::define('profile.view', [ProfilePolicy::class, 'view']);
        Gate::define('profile.update', [ProfilePolicy::class, 'update']);
        Gate::define('password.change', [ProfilePolicy::class, 'changePassword']);

        Gate::define('security.authentication.view', [SecurityPolicy::class, 'viewAuthentication']);
        Gate::define('security.authentication.update', [SecurityPolicy::class, 'updateAuthentication']);
        Gate::define('security.password_policy.view', [SecurityPolicy::class, 'viewPasswordPolicy']);
        Gate::define('security.password_policy.update', [SecurityPolicy::class, 'updatePasswordPolicy']);
        Gate::define('security.login_security.view', [SecurityPolicy::class, 'viewLoginSecurity']);
        Gate::define('security.login_security.update', [SecurityPolicy::class, 'updateLoginSecurity']);
        Gate::define('security.session.view', [SecurityPolicy::class, 'viewSession']);
        Gate::define('security.session.update', [SecurityPolicy::class, 'updateSession']);
        Gate::define('security.registration.view', [SecurityPolicy::class, 'viewRegistration']);
        Gate::define('security.registration.update', [SecurityPolicy::class, 'updateRegistration']);
        Gate::define('security.account_protection.view', [SecurityPolicy::class, 'viewAccountProtection']);
        Gate::define('security.account_protection.update', [SecurityPolicy::class, 'updateAccountProtection']);

        Gate::define('security.login_history.view', [SecurityPolicy::class, 'viewLoginHistory']);
    }

    /**
     * Record scheduler execution history automatically so the monitor always
     * has data even when tasks run via cron (not "Run Now").
     */
    private function registerSchedulerHistoryListeners(): void
    {
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            SchedulerHistory::create([
                'command' => $event->task->command ?? 'closure',
                'triggered_by' => 'scheduler',
                'status' => 'success',
                'duration_ms' => (int) ($event->runtime * 1000),
                'ran_at' => now(),
            ]);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            SchedulerHistory::create([
                'command' => $event->task->command ?? 'closure',
                'triggered_by' => 'scheduler',
                'status' => 'failed',
                'output' => $event->exception->getMessage(),
                'ran_at' => now(),
            ]);
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event): void {
            SchedulerHistory::create([
                'command' => $event->task->command ?? 'closure',
                'triggered_by' => 'scheduler',
                'status' => 'skipped',
                'ran_at' => now(),
            ]);
        });
    }

    /**
     * Grant the super_admin role unrestricted access to every Gate ability.
     * This runs before any policy or Gate check, so it short-circuits everything.
     *
     * Deliberately keyed on role NAME, not database ID — an ID-based check
     * (e.g. "role id 1") would silently grant unrestricted access to whatever
     * role happens to occupy that row after a reseed/migration, regardless of
     * its actual name or assigned permissions.
     */
    private function registerSuperAdminGate(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
    }

    /**
     * Auto-assign every newly created permission to the super_admin role.
     * This ensures super_admin always has all permissions, even after shield:generate.
     *
     * Looked up by name, not ID — the roles table is the single source of
     * truth for "what is super_admin", and role IDs are never authoritative.
     */
    private function registerPermissionObserver(): void
    {
        Permission::created(function (Permission $permission): void {
            $superAdmin = Role::where('name', 'super_admin')->first();

            if ($superAdmin) {
                $superAdmin->givePermissionTo($permission);
            }
        });
    }

    /**
     * Named rate limiters — evaluated per request so settings changes take effect immediately.
     * Routes reference these by name: throttle:login and throttle:password.reset
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $settings = app(LoginSecuritySettings::class);

            if (! $settings->throttling_enabled) {
                return [];
            }

            return Limit::perMinute(10)->by($request->input('email').'|'.$request->ip());
        });

        RateLimiter::for('password.reset', function (Request $request) {
            $settings = app(LoginSecuritySettings::class);

            if (! $settings->reset_throttling_enabled) {
                return [];
            }

            return Limit::perMinute(5)->by($request->ip());
        });

        // Public forms (Callback / Feedback / Support / General Inquiry).
        // Livewire actions never hit route-level throttle middleware — this
        // limiter is applied manually via ThrottlesLivewireRequests.
        RateLimiter::for('forms', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perDay(20)->by($request->ip()),
            ];
        });

        // RazorpayX instructor payout webhook — separate from every
        // booking-payment webhook limiter (Phase 16B, a distinct
        // financial domain). Generous enough for legitimate retry
        // storms from RazorpayX, tight enough to bound abuse of a
        // public, unauthenticated endpoint.
        RateLimiter::for('razorpayx-payout-webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Wallet recharge initiation — applied manually via
        // ThrottlesLivewireRequests (Livewire actions never hit
        // route-level throttle middleware). Per-student, not per-IP:
        // bounds repeated-click/retry provider-order creation without
        // punishing a shared network.
        RateLimiter::for('wallet-recharge-initiate', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->input('user_id'));
        });
    }
}
