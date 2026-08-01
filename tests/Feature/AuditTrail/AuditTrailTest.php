<?php

declare(strict_types=1);

namespace Tests\Feature\AuditTrail;

use App\Enums\ActivityActorType;
use App\Events\ActivityCreated;
use App\Models\Activity;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    // ── AuditTrailService::logUser ────────────────────────────────────────

    public function test_log_user_sets_actor_type_user(): void
    {
        $user = $this->user();
        $activity = $this->audit()->logUser($user, 'users', 'created', 'User created');

        $this->assertSame(ActivityActorType::User, $activity->actor_type);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertNull($activity->guest_name);
    }

    public function test_log_user_stores_request_context(): void
    {
        $user = $this->user();
        $activity = $this->audit()->logUser($user, 'users', 'updated', 'Profile updated');

        // In test context request()->ip() returns '127.0.0.1'
        $this->assertNotNull($activity->ip_address);
    }

    public function test_log_user_with_subject_and_properties(): void
    {
        $user = $this->user();
        $subject = $this->user();

        $activity = $this->audit()->logUser(
            user: $user,
            logName: 'users',
            event: 'roles_updated',
            description: 'Roles changed',
            subject: $subject,
            properties: ['roles' => ['admin']],
        );

        $this->assertSame($subject->id, (int) $activity->subject_id);
        $this->assertSame(['roles' => ['admin']], data_get($activity->properties, 'roles') !== null
            ? ['roles' => $activity->properties->get('roles')]
            : []);
    }

    // ── AuditTrailService::logOverride ────────────────────────────────────

    public function test_log_override_sets_actor_type_user(): void
    {
        $admin = $this->user();
        $activity = $this->audit()->logOverride($admin, 'instructor', 'admin_override', 'Force approved', 'because reasons');

        $this->assertSame(ActivityActorType::User, $activity->actor_type);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_log_override_always_stores_the_reason(): void
    {
        $activity = $this->audit()->logOverride($this->user(), 'instructor', 'admin_override', 'Force approved', 'urgent business need');

        $this->assertSame('urgent business need', $activity->properties->get('override_reason'));
    }

    public function test_log_override_always_flags_is_override(): void
    {
        $activity = $this->audit()->logOverride($this->user(), 'instructor', 'admin_override', 'Force approved', 'reason');

        $this->assertTrue($activity->properties->get('is_override'));
    }

    public function test_log_override_merges_additional_properties_with_the_reason(): void
    {
        $activity = $this->audit()->logOverride(
            $this->user(),
            'instructor',
            'admin_override',
            'Force approved',
            'reason',
            properties: ['previous_status' => 'submitted', 'new_status' => 'approved'],
        );

        $this->assertSame('submitted', $activity->properties->get('previous_status'));
        $this->assertSame('approved', $activity->properties->get('new_status'));
        $this->assertSame('reason', $activity->properties->get('override_reason'));
    }

    // ── AuditTrailService::logGuest ───────────────────────────────────────

    public function test_log_guest_sets_actor_type_guest(): void
    {
        $activity = $this->audit()->logGuest(
            logName: 'contact',
            event: 'contact_form_submitted',
            description: 'Contact form submitted',
            guestName: 'John Doe',
            guestEmail: 'john@example.com',
            guestPhone: '+1234567890',
        );

        $this->assertSame(ActivityActorType::Guest, $activity->actor_type);
        $this->assertNull($activity->causer_id);
        $this->assertSame('John Doe', $activity->guest_name);
        $this->assertSame('john@example.com', $activity->guest_email);
        $this->assertSame('+1234567890', $activity->guest_phone);
    }

    public function test_log_guest_does_not_require_all_guest_fields(): void
    {
        $activity = $this->audit()->logGuest('contact', 'contact_form_submitted', 'Submitted');

        $this->assertSame(ActivityActorType::Guest, $activity->actor_type);
        $this->assertNull($activity->guest_name);
        $this->assertNull($activity->guest_email);
        $this->assertNull($activity->guest_phone);
    }

    // ── AuditTrailService::logSystem ──────────────────────────────────────

    public function test_log_system_sets_actor_type_system(): void
    {
        $activity = $this->audit()->logSystem('scheduler_monitor', 'manually_ran', 'Task executed');

        $this->assertSame(ActivityActorType::System, $activity->actor_type);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->guest_name);
    }

    // ── Activity model helpers ────────────────────────────────────────────

    public function test_is_user_returns_true_for_user_activity(): void
    {
        $activity = $this->audit()->logUser($this->user(), 'users', 'created', 'created');

        $this->assertTrue($activity->isUser());
        $this->assertFalse($activity->isGuest());
        $this->assertFalse($activity->isSystem());
    }

    public function test_is_guest_returns_true_for_guest_activity(): void
    {
        $activity = $this->audit()->logGuest('contact', 'submitted', 'submitted');

        $this->assertTrue($activity->isGuest());
        $this->assertFalse($activity->isUser());
        $this->assertFalse($activity->isSystem());
    }

    public function test_is_system_returns_true_for_system_activity(): void
    {
        $activity = $this->audit()->logSystem('scheduler_monitor', 'ran', 'ran');

        $this->assertTrue($activity->isSystem());
        $this->assertFalse($activity->isUser());
        $this->assertFalse($activity->isGuest());
    }

    public function test_actor_name_for_user_returns_user_name(): void
    {
        $user = $this->user(['name' => 'Alice Smith']);
        $activity = $this->audit()->logUser($user, 'users', 'created', 'created');

        $this->assertSame('Alice Smith', $activity->actorName());
    }

    public function test_actor_name_for_guest_returns_guest_name(): void
    {
        $activity = $this->audit()->logGuest('contact', 'submitted', 'submitted', guestName: 'Bob Guest');

        $this->assertSame('Bob Guest', $activity->actorName());
    }

    public function test_actor_name_for_guest_returns_guest_when_no_name(): void
    {
        $activity = $this->audit()->logGuest('contact', 'submitted', 'submitted');

        $this->assertSame('Guest', $activity->actorName());
    }

    public function test_actor_name_for_system_returns_system(): void
    {
        $activity = $this->audit()->logSystem('scheduler_monitor', 'ran', 'ran');

        $this->assertSame('System', $activity->actorName());
    }

    public function test_actor_email_for_user_returns_user_email(): void
    {
        $user = $this->user(['email' => 'alice@example.com']);
        $activity = $this->audit()->logUser($user, 'users', 'created', 'created');

        $this->assertSame('alice@example.com', $activity->actorEmail());
    }

    public function test_actor_email_for_guest_returns_guest_email(): void
    {
        $activity = $this->audit()->logGuest('contact', 'submitted', 'submitted', guestEmail: 'bob@example.com');

        $this->assertSame('bob@example.com', $activity->actorEmail());
    }

    public function test_actor_email_for_system_returns_null(): void
    {
        $activity = $this->audit()->logSystem('scheduler_monitor', 'ran', 'ran');

        $this->assertNull($activity->actorEmail());
    }

    public function test_actor_description_for_user(): void
    {
        $user = $this->user(['name' => 'Alice', 'email' => 'alice@example.com']);
        $activity = $this->audit()->logUser($user, 'users', 'created', 'created');

        $this->assertStringContainsString('Alice', $activity->actorDescription());
        $this->assertStringContainsString('alice@example.com', $activity->actorDescription());
    }

    public function test_actor_description_for_guest(): void
    {
        $activity = $this->audit()->logGuest('contact', 'submitted', 'submitted', guestName: 'Bob', guestEmail: 'bob@example.com');

        $this->assertStringContainsString('Bob', $activity->actorDescription());
        $this->assertStringContainsString('bob@example.com', $activity->actorDescription());
    }

    public function test_actor_description_for_system_is_automated(): void
    {
        $activity = $this->audit()->logSystem('scheduler_monitor', 'ran', 'ran');

        $this->assertStringContainsString('automated', $activity->actorDescription());
    }

    // ── Raw activity() helper — backward compat auto-detect ───────────────

    public function test_raw_activity_helper_with_causer_auto_detects_user(): void
    {
        $user = $this->user();
        $activity = activity('users')->causedBy($user)->event('created')->log('Created');

        $this->assertSame(ActivityActorType::User, $activity->actor_type);
    }

    public function test_raw_activity_helper_without_causer_auto_detects_system(): void
    {
        $activity = activity('scheduler_monitor')->event('ran')->log('Task ran');

        $this->assertSame(ActivityActorType::System, $activity->actor_type);
    }

    // ── Migration backfill (simulated via DB insert) ──────────────────────

    public function test_backfill_assigns_user_type_to_records_with_causer(): void
    {
        // Simulate a pre-migration record: insert with causer_id but no actor_type
        DB::table('activity_log')->insert([
            'log_name' => 'users',
            'description' => 'Old record',
            'event' => 'created',
            'causer_type' => User::class,
            'causer_id' => $this->user()->id,
            'actor_type' => null, // pre-migration
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the backfill SQL (same logic as the migration)
        DB::statement("
            UPDATE activity_log
            SET actor_type = CASE
                WHEN causer_id IS NOT NULL THEN 'user'
                WHEN log_name = 'contact'  THEN 'guest'
                ELSE 'system'
            END
            WHERE actor_type IS NULL
        ");

        $record = Activity::where('description', 'Old record')->first();
        $this->assertSame(ActivityActorType::User, $record->actor_type);
    }

    public function test_backfill_assigns_system_type_to_records_without_causer(): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduler_monitor',
            'description' => 'Old system record',
            'event' => 'ran',
            'causer_type' => null,
            'causer_id' => null,
            'actor_type' => null,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement("
            UPDATE activity_log
            SET actor_type = CASE
                WHEN causer_id IS NOT NULL THEN 'user'
                WHEN log_name = 'contact'  THEN 'guest'
                ELSE 'system'
            END
            WHERE actor_type IS NULL
        ");

        $record = Activity::where('description', 'Old system record')->first();
        $this->assertSame(ActivityActorType::System, $record->actor_type);
    }

    public function test_backfill_assigns_guest_type_to_contact_records(): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'contact',
            'description' => 'Old contact record',
            'event' => 'contact_form_submitted',
            'causer_type' => null,
            'causer_id' => null,
            'actor_type' => null,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement("
            UPDATE activity_log
            SET actor_type = CASE
                WHEN causer_id IS NOT NULL THEN 'user'
                WHEN log_name = 'contact'  THEN 'guest'
                ELSE 'system'
            END
            WHERE actor_type IS NULL
        ");

        $record = Activity::where('description', 'Old contact record')->first();
        $this->assertSame(ActivityActorType::Guest, $record->actor_type);
    }

    // ── Notification pipeline compatibility ───────────────────────────────

    public function test_notification_pipeline_fires_for_user_activity(): void
    {
        $admin = $this->superAdmin();
        $user = $this->user();

        $this->audit()->logUser($user, 'users', 'created', 'User created', subject: $user);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    public function test_notification_pipeline_fires_for_guest_activity(): void
    {
        $admin = $this->superAdmin();

        $this->audit()->logGuest(
            logName: 'contact',
            event: 'contact_form_submitted',
            description: 'Contact form submitted',
            guestName: 'John',
            guestEmail: 'john@example.com',
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    public function test_notification_pipeline_does_not_fire_for_ignored_system_activity(): void
    {
        $this->superAdmin();

        // 'scheduler_monitor.manually_ran' is in the IGNORE list of NotificationMapper
        $this->audit()->logSystem('scheduler_monitor', 'manually_ran', 'Task ran');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_activity_created_event_dispatched_for_all_actor_types(): void
    {
        // Create the user first (User::factory triggers LogsActivity) then fake
        $user = $this->user();
        Event::fake([ActivityCreated::class]);

        $this->audit()->logUser($user, 'users', 'created', 'User action');
        $this->audit()->logGuest('contact', 'contact_form_submitted', 'Guest action');
        $this->audit()->logSystem('scheduler_monitor', 'ran', 'System action');

        Event::assertDispatchedTimes(ActivityCreated::class, 3);
    }

    public function test_activity_created_event_carries_actor_type(): void
    {
        $captured = [];
        Event::listen(ActivityCreated::class, function (ActivityCreated $e) use (&$captured): void {
            $captured[] = $e->activity->actor_type;
        });

        $user = $this->user();
        $this->audit()->logUser($user, 'users', 'created', 'created');
        $this->audit()->logGuest('contact', 'submitted', 'submitted');
        $this->audit()->logSystem('scheduler_monitor', 'ran', 'ran');

        $this->assertContains(ActivityActorType::User, $captured);
        $this->assertContains(ActivityActorType::Guest, $captured);
        $this->assertContains(ActivityActorType::System, $captured);
    }

    // ── Single audit table verification ──────────────────────────────────

    public function test_all_actor_types_write_to_same_table(): void
    {
        $user = $this->user();
        Activity::query()->delete(); // clear factory-generated user-creation log

        $this->audit()->logUser($user, 'users', 'created', 'User entry');
        $this->audit()->logGuest('contact', 'submitted', 'Guest entry');
        $this->audit()->logSystem('scheduler_monitor', 'ran', 'System entry');

        $this->assertDatabaseHas('activity_log', ['description' => 'User entry']);
        $this->assertDatabaseHas('activity_log', ['description' => 'Guest entry']);
        $this->assertDatabaseHas('activity_log', ['description' => 'System entry']);
        $this->assertDatabaseCount('activity_log', 3);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function audit(): AuditTrailService
    {
        return app(AuditTrailService::class);
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active', 'email_verified_at' => now()], $attrs));
    }

    private function superAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = $this->user();
        $admin->assignRole($role);

        return $admin;
    }
}
