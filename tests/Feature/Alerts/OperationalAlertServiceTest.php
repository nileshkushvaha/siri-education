<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Exceptions\InvalidOperationalAlertTransitionException;
use App\Alerts\Exceptions\OperationalAlertValidationException;
use App\Alerts\Services\OperationalAlertService;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\OperationalAlert;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The authoritative OperationalAlertService: creation/merge
 * deduplication, the one-active-alert-per-fingerprint guarantee,
 * new-episode-after-resolve behavior, lifecycle transitions with a
 * mandatory resolution reason, and authorization. This is a distinct
 * domain from compliance flags — none of these tests exercise
 * suspicious-activity data.
 */
class OperationalAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function signal(string $subjectId = 'booking-1', OperationalAlertSeverity $severity = OperationalAlertSeverity::High): OperationalAlertSignal
    {
        return new OperationalAlertSignal(
            type: OperationalAlertType::MeetingCreationFailed,
            category: OperationalAlertType::MeetingCreationFailed->category(),
            severity: $severity,
            title: 'Meeting creation failed',
            summary: 'Provider returned a failure.',
            subjectType: 'App\\Models\\Booking',
            subjectId: $subjectId,
            metadata: ['provider' => 'zoom'],
            occurredAt: Date::now()->toImmutable(),
        );
    }

    private function permittedAdmin(array $permissions): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $admin->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    // ── Create / merge deduplication ─────────────────────────────────────

    public function test_first_signal_creates_an_open_alert(): void
    {
        $alert = app(OperationalAlertService::class)->createOrMerge($this->signal());

        $this->assertSame(OperationalAlertStatus::Open, $alert->status);
        $this->assertSame(1, $alert->occurrence_count);
        $this->assertNotNull($alert->active_fingerprint);
        $this->assertSame(1, OperationalAlert::query()->count());
        $this->assertStringStartsWith('OPS-', $alert->reference);
    }

    public function test_a_repeat_signal_against_an_open_alert_merges_instead_of_creating(): void
    {
        $service = app(OperationalAlertService::class);

        $first = $service->createOrMerge($this->signal());
        $second = $service->createOrMerge($this->signal());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->fresh()->occurrence_count);
        $this->assertSame(1, OperationalAlert::query()->count());
    }

    public function test_a_merge_escalates_severity_to_the_higher_of_the_two(): void
    {
        $service = app(OperationalAlertService::class);

        $alert = $service->createOrMerge($this->signal(severity: OperationalAlertSeverity::Warning));
        $merged = $service->createOrMerge($this->signal(severity: OperationalAlertSeverity::Critical));

        $this->assertSame($alert->id, $merged->id);
        $this->assertSame(OperationalAlertSeverity::Critical, $merged->fresh()->severity);
    }

    public function test_a_merge_never_downgrades_severity(): void
    {
        $service = app(OperationalAlertService::class);

        $service->createOrMerge($this->signal(severity: OperationalAlertSeverity::Critical));
        $merged = $service->createOrMerge($this->signal(severity: OperationalAlertSeverity::Warning));

        $this->assertSame(OperationalAlertSeverity::Critical, $merged->fresh()->severity);
    }

    public function test_different_subjects_never_merge(): void
    {
        $service = app(OperationalAlertService::class);

        $service->createOrMerge($this->signal(subjectId: 'booking-1'));
        $service->createOrMerge($this->signal(subjectId: 'booking-2'));

        $this->assertSame(2, OperationalAlert::query()->count());
    }

    public function test_active_fingerprint_column_enforces_one_active_alert_per_fingerprint_at_the_database_level(): void
    {
        app(OperationalAlertService::class)->createOrMerge($this->signal());

        $this->expectException(UniqueConstraintViolationException::class);

        OperationalAlert::query()->create([
            'id' => (string) Str::uuid(),
            'reference' => 'OPS-COLLISION1',
            'type' => OperationalAlertType::MeetingCreationFailed,
            'category' => OperationalAlertCategory::BookingMeeting,
            'severity' => OperationalAlertSeverity::High,
            'status' => OperationalAlertStatus::Open,
            'title' => 'Duplicate',
            'summary' => 'Duplicate',
            'subject_type' => 'App\\Models\\Booking',
            'subject_id' => 'booking-1',
            'occurrence_count' => 1,
            'first_observed_at' => now(),
            'last_observed_at' => now(),
            'fingerprint' => 'alert:meeting_creation_failed:App\\Models\\Booking:booking-1',
            'active_fingerprint' => 'alert:meeting_creation_failed:App\\Models\\Booking:booking-1',
            'metadata' => [],
            'version' => 1,
        ]);
    }

    public function test_a_recurrence_after_resolution_starts_a_fresh_episode_not_a_reopen(): void
    {
        $admin = $this->permittedAdmin(['Resolve:OperationalAlert']);
        $service = app(OperationalAlertService::class);

        $alert = $service->createOrMerge($this->signal());
        $service->resolve($alert, $admin, 'Meeting was manually created.');

        $recurrence = $service->createOrMerge($this->signal());

        $this->assertNotSame($alert->id, $recurrence->id);
        $this->assertSame(OperationalAlertStatus::Open, $recurrence->status);
        $this->assertSame(2, OperationalAlert::query()->count());
        $this->assertSame(OperationalAlertStatus::Resolved, $alert->fresh()->status, 'The resolved episode remains resolved forever.');
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function test_acknowledge_transitions_open_to_acknowledged(): void
    {
        $admin = $this->permittedAdmin(['Acknowledge:OperationalAlert']);
        $service = app(OperationalAlertService::class);

        $alert = $service->createOrMerge($this->signal());
        $alert = $service->acknowledge($alert, $admin);

        $this->assertSame(OperationalAlertStatus::Acknowledged, $alert->status);
        $this->assertSame($admin->id, $alert->acknowledged_by);
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertNotNull($alert->fresh()->active_fingerprint, 'acknowledged alerts remain active');
    }

    public function test_resolve_requires_a_non_empty_reason(): void
    {
        $admin = $this->permittedAdmin(['Resolve:OperationalAlert']);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());

        $this->expectException(OperationalAlertValidationException::class);
        $service->resolve($alert, $admin, '   ');
    }

    public function test_resolve_clears_active_fingerprint_so_the_fingerprint_becomes_free(): void
    {
        $admin = $this->permittedAdmin(['Resolve:OperationalAlert']);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());

        $service->resolve($alert, $admin, 'Meeting was manually created.');

        $this->assertNull($alert->fresh()->active_fingerprint);
        $this->assertSame(OperationalAlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_resolved_to_acknowledged_is_an_invalid_transition(): void
    {
        $admin = $this->permittedAdmin(['Resolve:OperationalAlert', 'Acknowledge:OperationalAlert']);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());
        $service->resolve($alert, $admin, 'Done.');

        $this->expectException(InvalidOperationalAlertTransitionException::class);
        $service->acknowledge($alert->fresh(), $admin);
    }

    public function test_repeat_resolve_call_is_idempotent(): void
    {
        $admin = $this->permittedAdmin(['Resolve:OperationalAlert']);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());

        $service->resolve($alert, $admin, 'Fixed.');
        $again = $service->resolve($alert->fresh(), $admin, 'Fixed.');

        $this->assertSame(OperationalAlertStatus::Resolved, $again->status);
    }

    public function test_repeat_acknowledge_call_is_idempotent(): void
    {
        $admin = $this->permittedAdmin(['Acknowledge:OperationalAlert']);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());

        $service->acknowledge($alert, $admin);
        $again = $service->acknowledge($alert->fresh(), $admin);

        $this->assertSame(OperationalAlertStatus::Acknowledged, $again->status);
    }

    // ── Authorization ────────────────────────────────────────────────────

    public function test_admin_without_permission_cannot_acknowledge(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());

        $this->expectException(AuthorizationException::class);
        $service->acknowledge($alert, $unauthorized);
    }

    public function test_admin_without_permission_cannot_resolve(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $service = app(OperationalAlertService::class);
        $alert = $service->createOrMerge($this->signal());

        $this->expectException(AuthorizationException::class);
        $service->resolve($alert, $unauthorized, 'Reason.');
    }

    // ── Immutable history ────────────────────────────────────────────────

    public function test_an_alert_can_never_be_hard_deleted(): void
    {
        $alert = app(OperationalAlertService::class)->createOrMerge($this->signal());

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $alert->delete();
    }
}
