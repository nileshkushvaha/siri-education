<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagDecision;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Exceptions\ComplianceValidationException;
use App\Compliance\Exceptions\InvalidSuspiciousActivityFlagTransitionException;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Models\SuspiciousActivityFlag;
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
 * The authoritative ComplianceMonitoringService: creation/merge
 * deduplication, the one-active-flag-per-fingerprint guarantee,
 * cooldown-gated re-opening, review lifecycle transitions with
 * mandatory reasons, and authorization (including the self-review
 * exclusion). A flag is evidence for human review, never a sanction —
 * none of these tests exercise any booking/payment/wallet mutation.
 */
class ComplianceMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function signal(int $subjectId, int $cooldownMinutes = 60): SuspiciousActivitySignal
    {
        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedFailedLogins,
            ruleVersion: 1,
            subjectId: $subjectId,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::High,
            evidence: ['failed_login_count' => 5, 'window_minutes' => 30, 'threshold' => 5],
            thresholdSnapshot: ['enabled' => true, 'threshold' => 5],
            cooldownMinutes: $cooldownMinutes,
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

    public function test_first_signal_creates_an_open_flag(): void
    {
        $subject = User::factory()->create();

        $flag = app(ComplianceMonitoringService::class)->record($this->signal($subject->id));

        $this->assertNotNull($flag);
        $this->assertSame(SuspiciousActivityFlagStatus::Open, $flag->status);
        $this->assertSame(1, $flag->occurrence_count);
        $this->assertNotNull($flag->active_fingerprint);
        $this->assertSame(1, SuspiciousActivityFlag::query()->count());
    }

    public function test_a_repeat_signal_against_an_open_flag_merges_instead_of_creating(): void
    {
        $subject = User::factory()->create();
        $service = app(ComplianceMonitoringService::class);

        $first = $service->record($this->signal($subject->id));
        $second = $service->record($this->signal($subject->id));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->fresh()->occurrence_count);
        $this->assertSame(1, SuspiciousActivityFlag::query()->count());
    }

    public function test_rule_identity_and_original_evidence_snapshot_survive_a_merge(): void
    {
        $subject = User::factory()->create();
        $service = app(ComplianceMonitoringService::class);

        $flag = $service->record($this->signal($subject->id));
        $service->record($this->signal($subject->id));

        $fresh = $flag->fresh();
        $this->assertSame(SuspiciousActivityRuleCode::RepeatedFailedLogins, $fresh->rule_code);
        $this->assertSame(1, $fresh->rule_version);
        $this->assertSame(['enabled' => true, 'threshold' => 5], $fresh->threshold_snapshot);
    }

    public function test_active_fingerprint_column_enforces_one_active_flag_per_fingerprint_at_the_database_level(): void
    {
        $subject = User::factory()->create();
        app(ComplianceMonitoringService::class)->record($this->signal($subject->id));

        $this->expectException(UniqueConstraintViolationException::class);

        SuspiciousActivityFlag::query()->create([
            'id' => (string) Str::uuid(),
            'reference' => 'SAF-COLLISION1',
            'rule_code' => SuspiciousActivityRuleCode::RepeatedFailedLogins,
            'rule_version' => 1,
            'category' => SuspiciousActivityRuleCode::RepeatedFailedLogins->category(),
            'severity' => SuspiciousActivityFlagSeverity::High,
            'status' => SuspiciousActivityFlagStatus::Open,
            'subject_id' => $subject->id,
            'occurrence_count' => 1,
            'first_observed_at' => now(),
            'last_observed_at' => now(),
            'evidence' => [],
            'threshold_snapshot' => [],
            'fingerprint' => "compliance:repeated_failed_logins:user:{$subject->id}",
            'active_fingerprint' => "compliance:repeated_failed_logins:user:{$subject->id}",
            'version' => 1,
        ]);
    }

    public function test_resolved_flag_within_cooldown_suppresses_a_new_flag(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['Resolve:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);

        $flag = $service->record($this->signal($subject->id, cooldownMinutes: 60));
        $service->resolve($flag, $admin, 'Confirmed false alarm.', SuspiciousActivityFlagDecision::FalsePositive);

        $result = $service->record($this->signal($subject->id, cooldownMinutes: 60));

        $this->assertNull($result);
        $this->assertSame(1, SuspiciousActivityFlag::query()->count());
    }

    public function test_a_new_flag_may_be_created_once_the_cooldown_window_has_elapsed(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['Resolve:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);

        $flag = $service->record($this->signal($subject->id, cooldownMinutes: 60));
        $service->resolve($flag, $admin, 'Confirmed false alarm.', SuspiciousActivityFlagDecision::FalsePositive);

        Date::setTestNow(Date::now()->addMinutes(61));
        $result = $service->record($this->signal($subject->id, cooldownMinutes: 60));
        Date::setTestNow();

        $this->assertNotNull($result);
        $this->assertNotSame($flag->id, $result->id);
        $this->assertSame(2, SuspiciousActivityFlag::query()->count());
    }

    // ── Review lifecycle ─────────────────────────────────────────────────

    public function test_begin_review_transitions_open_to_in_review(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['BeginReview:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);

        $flag = $service->record($this->signal($subject->id));
        $flag = $service->startReview($flag, $admin);

        $this->assertSame(SuspiciousActivityFlagStatus::InReview, $flag->status);
        $this->assertSame($admin->id, $flag->reviewer_id);
        $this->assertNotNull($flag->fresh()->active_fingerprint, 'in-review flags remain active');
    }

    public function test_resolve_requires_a_non_empty_reason(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['Resolve:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($subject->id));

        $this->expectException(ComplianceValidationException::class);
        $service->resolve($flag, $admin, '   ', SuspiciousActivityFlagDecision::ConfirmedRisk);
    }

    public function test_dismiss_requires_a_non_empty_reason(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['Dismiss:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($subject->id));

        $this->expectException(ComplianceValidationException::class);
        $service->dismiss($flag, $admin, '');
    }

    public function test_resolve_clears_active_fingerprint_so_the_fingerprint_becomes_free(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['Resolve:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($subject->id));

        $service->resolve($flag, $admin, 'Confirmed risk after review.', SuspiciousActivityFlagDecision::ConfirmedRisk);

        $this->assertNull($flag->fresh()->active_fingerprint);
        $this->assertSame(SuspiciousActivityFlagStatus::Resolved, $flag->fresh()->status);
    }

    public function test_dismissed_to_in_review_is_an_invalid_transition(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['BeginReview:SuspiciousActivityFlag', 'Dismiss:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($subject->id));
        $service->dismiss($flag, $admin, 'Not a genuine concern.');

        $this->expectException(InvalidSuspiciousActivityFlagTransitionException::class);
        $service->startReview($flag->fresh(), $admin);
    }

    public function test_repeat_resolve_call_is_idempotent(): void
    {
        $subject = User::factory()->create();
        $admin = $this->permittedAdmin(['Resolve:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($subject->id));

        $service->resolve($flag, $admin, 'Confirmed risk.', SuspiciousActivityFlagDecision::ConfirmedRisk);
        $again = $service->resolve($flag->fresh(), $admin, 'Confirmed risk.', SuspiciousActivityFlagDecision::ConfirmedRisk);

        $this->assertSame(SuspiciousActivityFlagStatus::Resolved, $again->status);
    }

    // ── Authorization ────────────────────────────────────────────────────

    public function test_admin_cannot_review_a_flag_about_their_own_conduct(): void
    {
        $admin = $this->permittedAdmin(['Resolve:SuspiciousActivityFlag']);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($admin->id));

        $this->expectException(AuthorizationException::class);
        $service->resolve($flag, $admin, 'Reason.', SuspiciousActivityFlagDecision::ConfirmedRisk);
    }

    public function test_admin_without_permission_cannot_resolve(): void
    {
        $subject = User::factory()->create();
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $service = app(ComplianceMonitoringService::class);
        $flag = $service->record($this->signal($subject->id));

        $this->expectException(AuthorizationException::class);
        $service->resolve($flag, $unauthorized, 'Reason.', SuspiciousActivityFlagDecision::ConfirmedRisk);
    }
}
