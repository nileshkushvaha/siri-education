<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\TrackUserSession;
use App\Models\Activity;
use App\Models\LoginHistory;
use App\Models\User;
use App\Models\UserSession;
use App\Settings\RegistrationSettings;
use App\Settings\SessionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-1-23: authoritative idle-session-timeout
 * enforcement, extending the existing TrackUserSession middleware/
 * UserSession tracker rather than adding a second activity subsystem.
 *
 * Session continuity across two separate test HTTP calls requires
 * explicitly re-sending the (encrypted) session cookie — Laravel's test
 * client does not carry cookies between calls automatically, and
 * StartSession::getSession() always resolves the session id from the
 * incoming cookie, discarding whatever id a previous call used. See
 * continueSession().
 */
class IdleSessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    private function setTimeout(int $minutes): void
    {
        $settings = app(SessionSettings::class);
        $settings->idle_timeout = $minutes;
        $settings->save();
    }

    private function student(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('student');

        return $user;
    }

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** Re-sends the given session id as the request cookie so the same tracked session continues. */
    private function continueSession(string $sessionId): static
    {
        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    private function currentSessionId(): string
    {
        return $this->app->make('session')->getId();
    }

    // ── 1/2. Basic expiry lifecycle ─────────────────────────────────────────

    public function test_authenticated_student_request_succeeds_before_the_timeout(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(10));

        $this->continueSession($sessionId)->get(route('dashboard'))->assertOk();
        $this->assertTrue(auth()->check());
    }

    public function test_a_student_session_expires_after_the_configured_inactivity_period(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(35));

        $this->continueSession($sessionId)->get(route('dashboard'))->assertRedirect(route('auth.login'));
        $this->assertFalse(auth()->check());
    }

    public function test_an_instructor_session_follows_the_same_policy(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->instructor();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(35));

        $this->continueSession($sessionId)->get(route('dashboard'))->assertRedirect(route('auth.login'));
    }

    public function test_a_super_admin_filament_session_follows_the_same_policy(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(35));

        $response = $this->continueSession($sessionId)->get('/admin');
        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
        $this->assertFalse(auth()->check());
    }

    // ── 5/6. Exact boundary semantics ────────────────────────────────────────

    public function test_a_request_exactly_at_the_expiry_boundary_is_expired(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        // current_time >= last_activity_at + idle_timeout — exactly equal must expire.
        CarbonImmutable::setTestNow($now->addMinutes(30));

        $this->continueSession($sessionId)->get(route('dashboard'))->assertRedirect(route('auth.login'));
    }

    public function test_a_request_immediately_before_the_boundary_succeeds(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(30)->subSecond());

        $this->continueSession($sessionId)->get(route('dashboard'))->assertOk();
    }

    // ── 7/8. Activity refresh ordering ───────────────────────────────────────

    public function test_accepted_activity_advances_the_sessions_activity_timestamp(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(10));
        $this->continueSession($sessionId)->get(route('dashboard'))->assertOk();

        $tracked = UserSession::query()->find($sessionId);
        $this->assertNotNull($tracked);
        $this->assertTrue($tracked->last_activity_at->equalTo($now->addMinutes(10)));
    }

    public function test_expiry_is_checked_before_activity_is_refreshed(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(35));
        $this->continueSession($sessionId)->get(route('dashboard'))->assertRedirect(route('auth.login'));

        // The expired session's tracked row must be gone, not refreshed to "now".
        $this->assertNull(UserSession::query()->find($sessionId));
    }

    // ── 9/10. Unaffected surfaces ────────────────────────────────────────────

    public function test_an_unauthenticated_public_request_is_unaffected(): void
    {
        $this->setTimeout(30);

        $this->get(route('home'))->assertOk();
    }

    public function test_login_route_does_not_loop_for_a_guest(): void
    {
        $this->get(route('auth.login'))->assertOk();
    }

    public function test_webhook_routes_are_unaffected_since_they_use_the_stateless_api_group(): void
    {
        // Webhooks live in routes/api.php (the 'api' middleware group),
        // never routes/web.php — TrackUserSession was only ever added to
        // the 'web' group + the Filament panel's own stack, so this proves
        // that placement didn't leak into the stateless API surface.
        $route = Route::getRoutes()->getByName('api.bookings.payments.webhook');

        $this->assertNotNull($route);
        $this->assertNotContains(TrackUserSession::class, app('router')->gatherRouteMiddleware($route));
    }

    public function test_registration_route_does_not_loop_for_a_guest(): void
    {
        app(RegistrationSettings::class)->self_registration_enabled = true;
        app(RegistrationSettings::class)->save();

        $this->get(route('auth.register'))->assertOk();
    }

    // ── 11/12. Expiry mechanics ──────────────────────────────────────────────

    public function test_expiry_invalidates_the_session_and_regenerates_the_csrf_token(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();
        $tokenBefore = $this->app->make('session')->token();

        CarbonImmutable::setTestNow($now->addMinutes(35));
        $this->continueSession($sessionId)->get(route('dashboard'));

        $tokenAfter = $this->app->make('session')->token();
        $this->assertNotSame($tokenBefore, $tokenAfter);
        $this->assertNotSame($sessionId, $this->app->make('session')->getId());
    }

    public function test_the_expired_user_must_authenticate_again(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(35));
        $this->continueSession($sessionId)->get(route('dashboard'));

        $this->assertGuest();

        // A stale request to a protected route must land on the login page, not silently succeed.
        $this->get(route('dashboard'))->assertRedirect(route('auth.login'));
    }

    // ── 13/27. Remember-me ───────────────────────────────────────────────────

    public function test_remember_me_does_not_immediately_restore_the_expired_session(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        auth()->guard('web')->login($user, remember: true);
        $this->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        $recallerName = auth()->guard('web')->getRecallerName();
        $rememberToken = $user->fresh()->getRememberToken();
        $this->assertNotNull($rememberToken, 'A remember token must have been persisted.');

        // A real browser sends the recaller cookie alongside the session
        // cookie on every request — replicate that so SessionGuard::
        // logout() can actually see and clear it (it only queues a
        // "forget" cookie when a recaller is present on the CURRENT
        // request; Laravel does not proactively clear a cookie it never
        // saw).
        $recallerValue = $user->getAuthIdentifier().'|'.$rememberToken.'|'.$user->getAuthPassword();

        CarbonImmutable::setTestNow($now->addMinutes(35));
        $response = $this->continueSession($sessionId)
            ->withCookie($recallerName, $recallerValue)
            ->get(route('dashboard'));

        $response->assertRedirect(route('auth.login'));
        $this->assertGuest();

        // The recaller cookie for this browser must be cleared, not left valid.
        $cleared = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $recallerName);
        $this->assertNotNull($cleared, 'Expected the recaller cookie to be present in the response (cleared).');
        $this->assertTrue(blank($cleared->getValue()) || $cleared->getExpiresTime() < time());
    }

    public function test_normal_remember_me_login_still_works(): void
    {
        $this->setTimeout(30);

        $user = $this->student();
        auth()->guard('web')->login($user, remember: true);

        $this->assertNotNull($user->fresh()->getRememberToken());
        $this->assertTrue(auth()->check());
    }

    // ── 14/15. Multi-device isolation ────────────────────────────────────────

    public function test_another_device_session_for_the_same_user_is_not_terminated(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionA = $this->currentSessionId();

        // A second, independently tracked device/session for the SAME user.
        $sessionB = 'other-device-session-id';
        UserSession::query()->create([
            'session_id' => $sessionB,
            'user_id' => $user->id,
            'last_activity_at' => $now,
            'created_at' => $now,
        ]);

        CarbonImmutable::setTestNow($now->addMinutes(35));

        $this->continueSession($sessionA)->get(route('dashboard'))->assertRedirect(route('auth.login'));

        // Session A's tracked row is gone; session B's is untouched.
        $this->assertNull(UserSession::query()->find($sessionA));
        $this->assertNotNull(UserSession::query()->find($sessionB));
    }

    public function test_activity_in_one_session_does_not_refresh_another_sessions_timestamp(): void
    {
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $sessionB = 'a-different-browser-session';
        UserSession::query()->create([
            'session_id' => $sessionB,
            'user_id' => $user->id,
            'last_activity_at' => $now,
            'created_at' => $now,
        ]);

        CarbonImmutable::setTestNow($now->addMinutes(5));
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $untouched = UserSession::query()->find($sessionB);
        $this->assertNotNull($untouched);
        $this->assertTrue($untouched->last_activity_at->equalTo($now), 'A different session must never be refreshed by this one\'s activity.');
    }

    // ── 16/17. Settings changed mid-lifetime ─────────────────────────────────

    public function test_reducing_the_timeout_expires_on_the_next_request(): void
    {
        $this->setTimeout(60);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(40));
        $this->setTimeout(30);

        $this->continueSession($sessionId)->get(route('dashboard'))->assertRedirect(route('auth.login'));
    }

    public function test_increasing_the_timeout_preserves_a_session_that_has_not_exceeded_it(): void
    {
        $this->setTimeout(10);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(20));
        $this->setTimeout(60);

        $this->continueSession($sessionId)->get(route('dashboard'))->assertOk();
    }

    // ── 18. Zero/missing/invalid setting ─────────────────────────────────────

    public function test_a_zero_timeout_disables_custom_idle_expiry(): void
    {
        $this->setTimeout(0);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addDays(10));

        $this->continueSession($sessionId)->get(route('dashboard'))->assertOk();
    }

    // ── 19. JSON requests ─────────────────────────────────────────────────────

    public function test_json_requests_receive_the_correct_expired_response(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(35));

        $response = $this->withCredentials()->continueSession($sessionId)->getJson(route('dashboard'));
        $response->assertStatus(401);
    }

    // ── 20. Livewire coverage ─────────────────────────────────────────────────

    public function test_livewires_update_endpoint_carries_the_idle_timeout_middleware(): void
    {
        // Livewire::test() bypasses real HTTP routing entirely, so it
        // cannot exercise this middleware directly. Livewire's own update
        // route only ever receives the base 'web' middleware group (see
        // vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php),
        // so registering the check there (via bootstrap/app.php's
        // $middleware->web(append: [...])) is what actually protects it;
        // this asserts that registration held. The exact response
        // mechanics an expired Livewire request receives (401 JSON, no
        // stack trace) are already proven by the JSON-request test above,
        // since Livewire's fetch() sends Accept: application/json too.
        $route = Route::getRoutes()->getByName('default-livewire.update')
            ?? Route::getRoutes()->getByName('livewire.update');

        $this->assertNotNull($route, 'Expected Livewire\'s update route to be registered.');

        // gatherMiddleware() alone returns the route's own unexpanded
        // list (e.g. the literal string 'web'); resolving it through the
        // router expands group membership into actual middleware classes.
        $resolved = app('router')->gatherRouteMiddleware($route);
        $this->assertContains(TrackUserSession::class, $resolved);
    }

    // ── 25. Session/login history ────────────────────────────────────────────

    public function test_idle_expiry_is_recorded_in_login_history_and_the_audit_log(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        LoginHistory::query()->create([
            'user_id' => $user->id,
            'status' => 'success',
            'logged_in_at' => $now,
            'session_id' => $sessionId,
            'login_method' => 'password',
        ]);

        CarbonImmutable::setTestNow($now->addMinutes(35));
        $this->continueSession($sessionId)->get(route('dashboard'));

        $history = LoginHistory::query()->where('user_id', $user->id)->where('session_id', $sessionId)->firstOrFail();
        $this->assertNotNull($history->logged_out_at);

        $auditEntry = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'session_expired')
            ->where('subject_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($auditEntry);
        $this->assertSame('inactivity', $auditEntry->properties['reason']);
    }

    // ── 26. Normal logout ─────────────────────────────────────────────────────

    public function test_normal_logout_still_works(): void
    {
        $this->setTimeout(30);
        $user = $this->student();

        $this->actingAs($user)
            ->post(route('auth.logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    // ── 28. Laravel's own session lifetime is untouched ──────────────────────

    public function test_laravel_session_lifetime_config_is_never_mutated(): void
    {
        $before = config('session.lifetime');

        $this->setTimeout(999);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame($before, config('session.lifetime'));
    }

    // ── 29. Fresh login ───────────────────────────────────────────────────────

    public function test_a_fresh_login_initializes_activity_correctly(): void
    {
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $sessionId = $this->currentSessionId();
        $tracked = UserSession::query()->find($sessionId);

        $this->assertNotNull($tracked);
        $this->assertTrue($tracked->last_activity_at->equalTo($now));
    }

    // ── 30. No per-request audit noise ───────────────────────────────────────

    public function test_no_audit_row_is_created_for_ordinary_accepted_requests(): void
    {
        $this->setTimeout(30);
        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        CarbonImmutable::setTestNow($now);

        $user = $this->student();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $sessionId = $this->currentSessionId();

        CarbonImmutable::setTestNow($now->addMinutes(10));
        $this->continueSession($sessionId)->get(route('dashboard'))->assertOk();

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->where('event', 'session_expired')->count(),
        );
    }
}
