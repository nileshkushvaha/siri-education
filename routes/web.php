<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\InstructorDocumentDownloadController;
use App\Http\Controllers\Admin\PagePreviewController;
use App\Http\Controllers\Admin\PostPreviewController;
use App\Http\Controllers\Auth\AccountUnlockController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Booking\BookingWizardPageController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactFormController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\HomeworkResourceDownloadController;
use App\Http\Controllers\Dashboard\MessagingController;
use App\Http\Controllers\Dashboard\SupportCaseController;
use App\Http\Controllers\Faq\DashboardFaqController;
use App\Http\Controllers\Faq\PublicFaqController;
use App\Http\Controllers\Forms\CallbackController;
use App\Http\Controllers\Forms\FeedbackController;
use App\Http\Controllers\Forms\GeneralInquiryController;
use App\Http\Controllers\Forms\SupportController;
use App\Http\Controllers\Instructor\InstructorAnalyticsController;
use App\Http\Controllers\Instructor\InstructorApplicationController;
use App\Http\Controllers\Instructor\InstructorAvailabilityController;
use App\Http\Controllers\Instructor\InstructorController;
use App\Http\Controllers\Instructor\InstructorFinanceController;
use App\Http\Controllers\Instructor\InstructorHomeworkController;
use App\Http\Controllers\Instructor\InstructorHomeworkResourceController;
use App\Http\Controllers\Instructor\InstructorLearningPlanController;
use App\Http\Controllers\Instructor\InstructorLessonsController;
use App\Http\Controllers\Instructor\InstructorOnboardingController;
use App\Http\Controllers\Instructor\InstructorPayoutController;
use App\Http\Controllers\Instructor\InstructorQualityInsightsController;
use App\Http\Controllers\Instructor\InstructorStudentController;
use App\Http\Controllers\Instructor\InstructorVacationController;
use App\Http\Controllers\NewsletterUnsubscribeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\Profile\PhoneVerificationController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\PublicProfileController;
use App\Http\Controllers\Profile\SecurityController;
use App\Http\Controllers\Profile\SessionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\Student\StudentAttendanceController;
use App\Http\Controllers\Student\StudentBookingController;
use App\Http\Controllers\Student\StudentBookingHistoryController;
use App\Http\Controllers\Student\StudentCertificatesController;
use App\Http\Controllers\Student\StudentFavoriteInstructorController;
use App\Http\Controllers\Student\StudentHomeworkController;
use App\Http\Controllers\Student\StudentInvoiceDownloadController;
use App\Http\Controllers\Student\StudentInvoicesController;
use App\Http\Controllers\Student\StudentLearningGoalController;
use App\Http\Controllers\Student\StudentLearningPlanController;
use App\Http\Controllers\Student\StudentNotificationsController;
use App\Http\Controllers\Student\StudentOrdersController;
use App\Http\Controllers\Student\StudentPaymentsController;
use App\Http\Controllers\Student\StudentProgressController;
use App\Http\Controllers\Student\StudentReferralController;
use App\Http\Controllers\Student\StudentReviewsController;
use App\Http\Controllers\Student\StudentUpcomingClassesController;
use App\Http\Controllers\Student\StudentWaitlistController;
use App\Http\Controllers\Student\StudentWalletController;
use App\Http\Controllers\Student\StudentWishlistController;
use App\Http\Controllers\TagController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureInstructorWorkspaceAccess;
use App\Http\Middleware\EnsureSupportedFrontendPortalAudience;
use App\Models\User;
use App\Services\PortalResolver;
use App\Services\Student\StudentLifecycleService;
use App\Support\InstructorApplicationIntent;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Homepage ────────────────────────────────────────────────────────
Route::get('/', [PageController::class, 'home'])->name('home');

// ── Frontend Search + SEO ───────────────────────────────────────────
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');

// ── Booking wizard — authenticated students only; no guest booking of
// any kind exists anywhere in this domain. An unauthenticated visitor
// is redirected to login by the 'auth' middleware, which preserves
// this URL as the post-login intended redirect. ─────────────────────
Route::get('/book', [BookingWizardPageController::class, 'create'])
    ->middleware(['auth', 'email.verify.if.required', EnsureAccountIsActive::class, 'password.change.required'])
    ->name('booking.create');

// ── FAQ / Help Center (public — published, public-audience only) ──────
Route::get('/faqs', [PublicFaqController::class, 'index'])->name('faqs.index');

// ── Blog ─────────────────────────────────────────────────────────────
Route::get('/blog', [PostController::class, 'index'])->name('blog.index');
Route::get('/blog/category/{category:slug}', [CategoryController::class, 'show'])->name('blog.category');
Route::get('/blog/tag/{tag:slug}', [TagController::class, 'show'])->name('blog.tag');
Route::get('/blog/{slug}', [PostController::class, 'show'])->name('blog.show');

// ── Contact Form Submission ─────────────────────────────────────────
Route::post('/contact/submit', [ContactFormController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('contact.submit');

// ── Public Forms (Callback / Feedback / Support / General Inquiry) ───
Route::get('/callback', [CallbackController::class, 'show'])->name('forms.callback');
Route::get('/feedback', [FeedbackController::class, 'show'])->name('forms.feedback');
Route::get('/support', [SupportController::class, 'show'])->name('forms.support');
Route::get('/inquiry', [GeneralInquiryController::class, 'show'])->name('forms.inquiry');

// ── Newsletter unsubscribe (token-based, no auth) ─────────────────────
Route::get('/newsletter/unsubscribe/{token}', NewsletterUnsubscribeController::class)->name('newsletter.unsubscribe');

// ── Dashboard (Frontend Portal — see PortalResolver) ─────────────────
Route::get('/dashboard', DashboardController::class)->name('dashboard')
    ->middleware(['auth', 'email.verify.if.required', EnsureAccountIsActive::class, 'password.change.required', 'frontend.portal', EnsureSupportedFrontendPortalAudience::class]);

// ── Frontend Auth (guests only) ─────────────────────────────────────
Route::name('auth.')->middleware('guest')->group(function (): void {

    // Registration is the single, role-neutral entry point for every
    // account type: a student registers directly, and an instructor
    // applicant arrives via ?intent=instructor from /become-instructor
    // (see InstructorApplicationIntent). Both routes are guarded at the
    // middleware layer; the POST shares the 'login' throttle limiter the
    // Livewire form already applies via ThrottlesLivewireRequests, so
    // the threshold and settings toggle live in exactly one place
    // (AppServiceProvider). The previous student-only URL is preserved
    // below as a permanent redirect for old bookmarks/SEO.
    Route::get('/register', [RegisterController::class, 'showForm'])->middleware('registration.enabled')->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('registration.enabled', 'throttle:login')->name('register.store');

    // Login — EnsureLoginEnabled blocks POST when login is disabled
    Route::get('/login', [LoginController::class, 'showForm'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('login.enabled', 'throttle:login')->name('login.store');

    // Forgot Password
    Route::get('/forgot-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email')
        ->middleware('throttle:password.reset');

    // Reset Password
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showForm'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');

    // Account Unlock (self-service — accessible without login)
    Route::get('/unlock-account', [AccountUnlockController::class, 'show'])->name('account.unlock');
    Route::post('/unlock-account', [AccountUnlockController::class, 'unlock'])->name('account.unlock.process');

    // Public resend verification (for users who try to login before verifying)
    Route::post('/resend-verification-email', function (Request $request) {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', strtolower($request->input('email')))
            ->whereNull('email_verified_at')
            ->first();

        // Always show success to prevent email enumeration
        if ($user) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('success', 'Verification email sent! Please check your inbox.');
    })->middleware('throttle:3,1')->name('verification.resend.guest');
});

// Permanent redirect for the previous student-only registration URL —
// preserves bookmarks and SEO value. A closure rather than the bare
// Route::redirect() helper, because that helper drops the query
// string: a bookmarked /student-registration?intent=instructor must
// still land the applicant back on the instructor flow.
Route::get('/student-registration', fn (Request $request) => redirect()->route('auth.register', $request->query(), 301));

// ── Auth — requires authenticated user ──────────────────────────────
Route::name('auth.')->middleware('auth')->group(function (): void {

    // Logout
    Route::post('/logout', LogoutController::class)->name('logout');

    // Email Verification notice — redirect away if already verified
    Route::get('/verify-email', function () {
        $user = auth()->user();
        if ($user->hasVerifiedEmail()) {
            return redirect(app(PortalResolver::class)->loginRedirect($user));
        }

        return view('auth.verify-email');
    })->name('verification.notice');

    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            if (InstructorApplicationIntent::consume()) {
                return redirect()->route('dashboard.instructor.onboarding')
                    ->with('success', 'Your email is already verified.');
            }

            return redirect(app(PortalResolver::class)->loginRedirect($user))
                ->with('success', 'Your email is already verified.');
        }

        $request->fulfill();
        $user->update(['status' => User::STATUS_ACTIVE]);
        app(StudentLifecycleService::class)->activateFromVerification($user);

        if (InstructorApplicationIntent::consume()) {
            return redirect()->route('dashboard.instructor.onboarding')
                ->with('success', 'Email verified! Continue your instructor application below.');
        }

        return redirect()->route('auth.verified');
    })->middleware('signed')->name('verification.verify');

    Route::post('/resend-verification', function (Request $request) {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return redirect(app(PortalResolver::class)->loginRedirect($user));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('resent', true);
    })->middleware('throttle:6,1')->name('verification.resend');

    // Verification success page
    Route::get('/email-verified', fn () => view('auth.verified'))->name('verified');

    // ── Force password change (no email.verify or password.change middlewares — avoids loop) ──
    Route::get('/password/change-required', [ForcePasswordChangeController::class, 'showForm'])->name('password.change-required');
    Route::post('/password/change-required', [ForcePasswordChangeController::class, 'store'])->name('password.change-required.store');
});

// ── Student Dashboard sub-pages (auth + active account + frontend portal) ──
Route::prefix('dashboard')->name('dashboard.')->middleware([
    'auth',
    'email.verify.if.required',
    EnsureAccountIsActive::class,
    'password.change.required',
    'frontend.portal',
    EnsureSupportedFrontendPortalAudience::class,
])->group(function (): void {
    Route::get('/progress', [StudentProgressController::class,     'index'])->name('progress');
    Route::get('/certificates', [StudentCertificatesController::class, 'index'])->name('certificates');
    Route::get('/orders', [StudentOrdersController::class,       'index'])->name('orders');
    Route::get('/wishlist', [StudentWishlistController::class,     'index'])->name('wishlist');
    Route::get('/learning-goals', [StudentLearningGoalController::class, 'index'])->name('learning-goals');
    Route::get('/learning-plans', [StudentLearningPlanController::class, 'index'])->name('learning-plans');
    Route::post('/favorite-instructors/{instructor}', [StudentFavoriteInstructorController::class, 'store'])->name('favorite-instructors.store');
    Route::delete('/favorite-instructors/{instructor}', [StudentFavoriteInstructorController::class, 'destroy'])->name('favorite-instructors.destroy');
    Route::get('/waitlist', [StudentWaitlistController::class, 'index'])->name('waitlist');
    Route::post('/waitlist/{instructor}', [StudentWaitlistController::class, 'store'])->name('waitlist.store');
    Route::delete('/waitlist/{instructor}', [StudentWaitlistController::class, 'destroy'])->name('waitlist.destroy');
    Route::get('/reviews', [StudentReviewsController::class,      'index'])->name('reviews');
    Route::get('/notifications', [StudentNotificationsController::class, 'index'])->name('notifications');
    Route::post('/notifications/read-all', [StudentNotificationsController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [StudentNotificationsController::class, 'markRead'])->name('notifications.read');
    Route::get('/faqs', [DashboardFaqController::class, 'index'])->name('faqs');
    Route::get('/instructor/onboarding', [InstructorOnboardingController::class, 'show'])->name('instructor.onboarding');

    // ── Instructor teaching workspace — gated behind
    //    InstructorStatus::publiclyVisible() (Approved/Active/Vacation).
    //    An instructor who hasn't cleared review, or who is
    //    suspended/archived, is redirected to their application status
    //    page instead (see EnsureInstructorWorkspaceAccess). ──
    Route::middleware([EnsureInstructorWorkspaceAccess::class])->group(function (): void {
        Route::get('/instructor/learning-plans', [InstructorLearningPlanController::class, 'index'])->name('instructor.learning-plans');
        Route::get('/instructor/availability', [InstructorAvailabilityController::class, 'index'])->name('instructor.availability');
        // Self-service vacation toggle — a lifecycle state transition
        // (InstructorOnboardingService), never a separate domain.
        Route::get('/instructor/vacation', [InstructorVacationController::class, 'index'])->name('instructor.vacation');
        // Read-only student roster, derived entirely from Lesson — there is
        // no separate student-relationship table.
        Route::get('/instructor/students', [InstructorStudentController::class, 'index'])->name('instructor.students');
        Route::get('/instructor/students/{student}', [InstructorStudentController::class, 'show'])->name('instructor.students.show');
        // The instructor's own lesson list, hosting the private
        // student-feedback form for completed lessons. No admin/Filament
        // surface; feedback here never edits a lesson, booking, or outcome.
        Route::get('/instructor/lessons', [InstructorLessonsController::class, 'index'])->name('instructor.lessons');
        // Instructor-facing homework review queue (reuses the existing
        // app/Homework/* domain; grading writes go through
        // HomeworkService::review(), never straight to the model).
        Route::get('/instructor/homework', [InstructorHomeworkController::class, 'index'])->name('instructor.homework');
        // GAP-022 (37A) — instructor's own reusable resource library.
        Route::get('/instructor/homework/resources', [InstructorHomeworkResourceController::class, 'index'])->name('instructor.homework.resources');
        // Instructor-facing quality insights — read-only; the instructor
        // never moderates, resolves reports, or touches an aggregate here.
        Route::get('/instructor/quality-insights', [InstructorQualityInsightsController::class, 'index'])->name('instructor.quality-insights');
        // Read-only analytics foundation; aggregates only, never a raw
        // collection materialized then counted in PHP.
        Route::get('/instructor/analytics', [InstructorAnalyticsController::class, 'index'])->name('instructor.analytics');
        // Read-only earning/settlement visibility; no mutation route exists
        // here — all writes stay staff-only via InstructorEarningService.
        Route::get('/instructor/earnings', [InstructorFinanceController::class, 'earnings'])->name('instructor.earnings');
        Route::get('/instructor/settlements', [InstructorFinanceController::class, 'settlements'])->name('instructor.settlements');
        // Payout methods & withdrawals — page shells only; no route here
        // can move money.
        Route::get('/instructor/payout-methods', [InstructorPayoutController::class, 'payoutMethods'])->name('instructor.payout-methods');
        Route::get('/instructor/withdrawals', [InstructorPayoutController::class, 'withdrawals'])->name('instructor.withdrawals');
    });

    Route::post('/instructor/start', [InstructorOnboardingController::class, 'start'])->name('instructor.start');
    Route::post('/instructor/submit', [InstructorOnboardingController::class, 'submit'])->name('instructor.submit');

    // ── Student Dashboard — Booking Engine sections (Livewire-backed) ──
    Route::get('/upcoming-classes', [StudentUpcomingClassesController::class, 'index'])->name('upcoming-classes');
    Route::get('/my-bookings', [StudentBookingHistoryController::class, 'index'])->name('my-bookings');
    Route::get('/payments', [StudentPaymentsController::class, 'index'])->name('payments');
    Route::get('/wallet', [StudentWalletController::class, 'index'])->name('wallet');
    Route::get('/invoices', [StudentInvoicesController::class, 'index'])->name('invoices');
    // Authorization is re-checked inside the controller on every
    // request (InvoicePolicy::view()) — owner or View:Invoice only.
    Route::get('/invoices/{invoice}/download', StudentInvoiceDownloadController::class)->name('invoices.download');
    Route::get('/refer-a-friend', [StudentReferralController::class, 'index'])->name('refer-a-friend');
    Route::get('/homework', [StudentHomeworkController::class, 'index'])->name('homework');
    // GAP-022 — authorization is re-checked inside the controller on
    // every request (HomeworkAssignmentPolicy::view()); shared by
    // student and instructor since either may be the authorized viewer.
    Route::get('/homework/resources/{media}/download', HomeworkResourceDownloadController::class)->name('homework.resources.download');
    Route::get('/attendance', [StudentAttendanceController::class, 'index'])->name('attendance');

    // ── Support Cases (Phase 31, GAP-016) — shared by student and
    //    instructor audiences; SupportCaseType is derived from the
    //    acting user's active workspace, not a separate route group.
    //    Authorization is re-checked inside the controller on every
    //    request (SupportCasePolicy) — owner or explicit permission only.
    Route::get('/support-cases', [SupportCaseController::class, 'index'])->name('support-cases');
    Route::get('/support-cases/create', [SupportCaseController::class, 'create'])->name('support-cases.create');
    Route::post('/support-cases', [SupportCaseController::class, 'store'])->name('support-cases.store');
    Route::get('/support-cases/{supportCase}', [SupportCaseController::class, 'show'])->name('support-cases.show');
    Route::post('/support-cases/{supportCase}/reply', [SupportCaseController::class, 'reply'])->name('support-cases.reply');
    Route::post('/support-cases/{supportCase}/reopen', [SupportCaseController::class, 'reopen'])->name('support-cases.reopen');

    // ── Messaging (Phase 32, GAP-017) — shared by student and
    //    instructor audiences; eligibility (confirmed paid booking or
    //    active learning plan) is re-checked on every send, never just
    //    at conversation-open time. Authorization is re-checked inside
    //    the controller on every request (ConversationPolicy).
    Route::get('/messages', [MessagingController::class, 'index'])->name('messages');
    Route::get('/messages/start/{target}', [MessagingController::class, 'start'])->name('messages.start');
    Route::get('/messages/{conversation}', [MessagingController::class, 'show'])->name('messages.show');
    Route::post('/messages/{conversation}/reply', [MessagingController::class, 'reply'])->name('messages.reply');
    Route::post('/messages/{conversation}/report/{message}', [MessagingController::class, 'report'])->name('messages.report');
    Route::post('/messages/{conversation}/close', [MessagingController::class, 'close'])->name('messages.close');

    Route::prefix('bookings')->name('bookings.')->group(function (): void {
        Route::get('/', [StudentBookingController::class, 'index'])->name('index');
        Route::get('/teachers', [StudentBookingController::class, 'teachers'])->name('teachers');
        Route::get('/previous-teachers', [StudentBookingController::class, 'previousTeachers'])->name('previous-teachers');
        Route::get('/slots', [StudentBookingController::class, 'slots'])->name('slots');
        Route::post('/', [StudentBookingController::class, 'store'])->name('store');
    });
});

// ── Instructors (public — visibility enforced in the controller) ──────
Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
Route::get('/instructors/{user:slug}', [InstructorController::class, 'show'])->name('instructors.show');

// ── Become an Instructor (public — guests and authenticated users;
//    read-only marketing/status entry point, see InstructorApplicationController) ──
Route::get('/become-instructor', [InstructorApplicationController::class, 'show'])->name('instructor.apply');

// ── Public Profile (guests + authenticated — visibility enforced in the controller) ──
Route::get('/profile/{user}', [PublicProfileController::class, 'show'])->name('profile.public');

// ── Profile (auth + conditional email verification + active + password) ─────────
Route::prefix('profile')->name('profile.')->middleware([
    'auth',
    'email.verify.if.required',
    EnsureAccountIsActive::class,
    'password.change.required',
    'frontend.portal',
    EnsureSupportedFrontendPortalAudience::class,
])->group(function (): void {

    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::post('/', [ProfileController::class, 'update'])->name('update');
    Route::post('/password', [ProfileController::class, 'changePassword'])->name('password');
    Route::post('/avatar', [ProfileController::class, 'uploadAvatar'])->name('avatar.upload');
    Route::delete('/avatar', [ProfileController::class, 'deleteAvatar'])->name('avatar.delete');
    Route::post('/cover', [ProfileController::class, 'uploadCover'])->name('cover.upload');
    Route::delete('/cover', [ProfileController::class, 'deleteCover'])->name('cover.delete');
    Route::post('/visibility', [ProfileController::class, 'updateVisibility'])->name('visibility.update');
    Route::post('/phone/verification/send', [PhoneVerificationController::class, 'send'])->name('phone.verification.send');
    Route::post('/phone/verification/verify', [PhoneVerificationController::class, 'verify'])->name('phone.verification.verify');

    // Session Management
    Route::delete('/sessions/all', [SessionController::class, 'revokeAll'])->name('sessions.revoke-all');
    Route::delete('/sessions/{id}', [SessionController::class, 'revoke'])->name('sessions.revoke');

    // Security alert preferences
    Route::post('/security/alerts', [SecurityController::class, 'updateAlerts'])->name('security.alerts');
});

// ── Admin Routes (auth + conditional email verification + active + password) ─────
Route::prefix('admin')->name('admin.')->middleware([
    'auth',
    'email.verify.if.required',
    EnsureAccountIsActive::class,
    'password.change.required',
])->group(function (): void {
    // Page Preview
    Route::get('/pages/{page}/preview', PagePreviewController::class)->name('pages.preview');
    // Post Preview
    Route::get('/posts/{post}/preview', PostPreviewController::class)->name('posts.preview');
    // Instructor KYC document download — authorization is re-checked
    // inside the controller on every request; see InstructorDocumentPolicy.
    Route::get('/instructor-documents/{media}/download', InstructorDocumentDownloadController::class)
        ->name('instructor-documents.download');
});

// ── Public Pages (CMS) Catch-all (must stay last) ───────────────────
Route::get('/{slug}', [PageController::class, 'show'])->name('page.show');
