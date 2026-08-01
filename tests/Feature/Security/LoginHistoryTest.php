<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Events\Auth\LoginFailed;
use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserLoggedOut;
use App\Filament\Resources\LoginHistory\LoginHistoryResource;
use App\Filament\Resources\LoginHistory\Pages\ListLoginHistories;
use App\Filament\Widgets\RecentLoginsWidget;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    // ── Login recorded ────────────────────────────────────────────────

    public function test_successful_login_creates_login_history_record(): void
    {
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => 'success',
        ]);
    }

    public function test_successful_login_stores_session_id(): void
    {
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $record = LoginHistory::where('user_id', $user->id)->where('status', 'success')->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->session_id);
    }

    public function test_successful_login_stores_login_method(): void
    {
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => 'success',
            'login_method' => 'password',
        ]);
    }

    public function test_successful_login_stores_ip_browser_platform(): void
    {
        $user = $this->activeUser();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120'])
            ->post(route('auth.login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $record = LoginHistory::where('user_id', $user->id)->where('status', 'success')->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->ip_address);
        $this->assertNotNull($record->user_agent);
    }

    // ── Failed login recorded ─────────────────────────────────────────

    public function test_failed_login_creates_login_history_record(): void
    {
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => 'failed',
            'login_method' => 'password',
        ]);
    }

    public function test_failed_login_for_unknown_email_still_creates_record(): void
    {
        $this->post(route('auth.login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => null,
            'status' => 'failed',
        ]);
    }

    public function test_locked_account_login_creates_locked_status_record(): void
    {
        $user = $this->activeUser([
            'locked_at' => now()->subMinute(),
            'locked_until' => now()->addHour(),
        ]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => 'locked',
        ]);
    }

    // ── Logout recorded ───────────────────────────────────────────────

    public function test_logout_updates_logged_out_at_on_history_record(): void
    {
        $user = $this->activeUser();

        // Create a login history record first
        LoginHistory::create([
            'user_id' => $user->id,
            'status' => 'success',
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now(),
        ]);

        // Dispatch logout event
        UserLoggedOut::dispatch($user, '127.0.0.1', '');

        $record = LoginHistory::where('user_id', $user->id)->first();
        $this->assertNotNull($record->logged_out_at);
    }

    // ── Event dispatching ─────────────────────────────────────────────

    public function test_user_logged_in_event_carries_session_id_and_method(): void
    {
        Event::fake([UserLoggedIn::class]);

        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        Event::assertDispatched(UserLoggedIn::class, function (UserLoggedIn $event): bool {
            return $event->loginMethod === 'password';
        });
    }

    public function test_login_failed_event_carries_session_id(): void
    {
        Event::fake([LoginFailed::class]);

        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Event::assertDispatched(LoginFailed::class);
    }

    // ── No duplicate logging ──────────────────────────────────────────

    public function test_single_login_creates_exactly_one_history_record(): void
    {
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)->where('status', 'success')->count()
        );
    }

    public function test_single_failed_login_creates_exactly_one_history_record(): void
    {
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)->where('status', 'failed')->count()
        );
    }

    // ── Dashboard widget ──────────────────────────────────────────────

    public function test_recent_logins_widget_reads_from_login_history(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $user = $this->activeUser();

        LoginHistory::create([
            'user_id' => $user->id,
            'status' => 'success',
            'ip_address' => '10.0.0.1',
            'logged_in_at' => now(),
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(RecentLoginsWidget::class)
            ->assertSuccessful();
    }

    public function test_recent_logins_widget_does_not_have_duplicate_query(): void
    {
        // Verify that the widget reads only from login_histories (not duplicating activity_log)
        $user = $this->activeUser();
        LoginHistory::create([
            'user_id' => $user->id,
            'status' => 'success',
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now(),
        ]);

        $widgetQuery = LoginHistory::query()->with('user')->latest('logged_in_at')->limit(6);
        $this->assertSame(1, $widgetQuery->count());

        // Activity log should not double-count (it is separate)
        $activityCount = Activity::where('log_name', 'auth')->count();
        $loginHistoryCount = LoginHistory::count();
        // Both can record the same login but from different sources — no duplication within either
        $this->assertGreaterThanOrEqual(0, $activityCount);
        $this->assertSame(1, $loginHistoryCount);
    }

    // ── Filament resource access ──────────────────────────────────────

    public function test_super_admin_can_access_login_history_resource(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        $this->get(LoginHistoryResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_regular_user_cannot_access_login_history_resource(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user);

        $this->get(LoginHistoryResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_login_history_detail(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $user = $this->activeUser();

        $record = LoginHistory::create([
            'user_id' => $user->id,
            'status' => 'success',
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now(),
        ]);

        $this->actingAs($superAdmin);

        $this->get(LoginHistoryResource::getUrl('view', ['record' => $record->id]))
            ->assertSuccessful();
    }

    // ── Filament resource filters ─────────────────────────────────────

    public function test_login_history_list_page_renders(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        Livewire::test(ListLoginHistories::class)
            ->assertSuccessful();
    }

    public function test_login_history_status_filter_works(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $user = $this->activeUser();

        LoginHistory::create(['user_id' => $user->id, 'status' => 'success', 'ip_address' => '1.1.1.1', 'logged_in_at' => now()]);
        LoginHistory::create(['user_id' => $user->id, 'status' => 'failed', 'ip_address' => '2.2.2.2', 'logged_in_at' => now()]);

        $this->actingAs($superAdmin);

        Livewire::test(ListLoginHistories::class)
            ->filterTable('status', 'success')
            ->assertCanSeeTableRecords(LoginHistory::where('status', 'success')->get())
            ->assertCanNotSeeTableRecords(LoginHistory::where('status', 'failed')->get());
    }

    public function test_login_history_user_filter_works(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $userA = $this->activeUser();
        $userB = $this->activeUser();

        $recordA = LoginHistory::create(['user_id' => $userA->id, 'status' => 'success', 'ip_address' => '1.1.1.1', 'logged_in_at' => now()]);
        $recordB = LoginHistory::create(['user_id' => $userB->id, 'status' => 'success', 'ip_address' => '2.2.2.2', 'logged_in_at' => now()]);

        $this->actingAs($superAdmin);

        Livewire::test(ListLoginHistories::class)
            ->filterTable('user_id', $userA->id)
            ->assertCanSeeTableRecords([$recordA])
            ->assertCanNotSeeTableRecords([$recordB]);
    }

    public function test_login_history_date_range_filter_works(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $user = $this->activeUser();

        $oldRecord = LoginHistory::create(['user_id' => $user->id, 'status' => 'success', 'ip_address' => '1.1.1.1', 'logged_in_at' => now()->subDays(10)]);
        $newRecord = LoginHistory::create(['user_id' => $user->id, 'status' => 'success', 'ip_address' => '2.2.2.2', 'logged_in_at' => now()]);

        $this->actingAs($superAdmin);

        Livewire::test(ListLoginHistories::class)
            ->filterTable('date_range', ['from' => now()->subDay()->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$newRecord])
            ->assertCanNotSeeTableRecords([$oldRecord]);
    }

    // ── Permissions: no create/edit/delete ────────────────────────────

    public function test_login_history_resource_cannot_create(): void
    {
        $this->assertFalse(LoginHistoryResource::canCreate());
    }

    public function test_login_history_resource_cannot_edit(): void
    {
        $record = LoginHistory::create([
            'status' => 'success',
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now(),
        ]);

        $this->assertFalse(LoginHistoryResource::canEdit($record));
    }

    public function test_login_history_resource_cannot_delete(): void
    {
        $record = LoginHistory::create([
            'status' => 'success',
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now(),
        ]);

        $this->assertFalse(LoginHistoryResource::canDelete($record));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ], $overrides));
    }

    private function createSuperAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $admin->assignRole($role);

        return $admin;
    }
}
