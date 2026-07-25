<?php

declare(strict_types=1);

namespace Tests\Feature\SupportCases;

use App\Models\Activity;
use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\Enums\SupportCaseResolutionType;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Exceptions\InvalidSupportCaseTransitionException;
use App\SupportCases\Services\SupportCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §25.9-25.10/§25.17/§25.18/§25.31-§25.33: the case status
 * lifecycle, assignment/reassignment, escalation, resolution, closure,
 * and reopening — all enforced centrally through
 * TransitionSupportCaseStatusAction/SupportCaseService.
 */
class SupportCaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    private function openCase(?User $student = null): SupportCase
    {
        return SupportCase::factory()->forStudent($student ?? $this->student())->create();
    }

    public function test_assigning_a_case_moves_it_from_open_to_in_progress(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();

        $updated = app(SupportCaseService::class)->assign($case, $manager, $manager);

        $this->assertSame(SupportCaseStatus::InProgress, $updated->status);
        $this->assertSame($manager->id, $updated->assigned_to);
        $this->assertNotNull($updated->assigned_at);
    }

    public function test_reassigning_a_case_does_not_change_its_status(): void
    {
        $case = $this->openCase();
        $service = app(SupportCaseService::class);
        $firstManager = $this->manager();
        $secondManager = $this->manager();

        $case = $service->assign($case, $firstManager, $firstManager);
        $case = $service->escalate($case, $firstManager, 'Needs finance review');
        $case = $service->assign($case, $firstManager, $secondManager);

        $this->assertSame(SupportCaseStatus::Escalated, $case->status);
        $this->assertSame($secondManager->id, $case->assigned_to);
    }

    public function test_escalation_requires_a_reason(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();

        $this->expectException(InvalidSupportCaseTransitionException::class);
        app(SupportCaseService::class)->escalate($case, $manager, '');
    }

    public function test_resolution_requires_a_summary(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();

        $this->expectException(InvalidSupportCaseTransitionException::class);
        app(SupportCaseService::class)->resolve($case, $manager, SupportCaseResolutionType::UserAdvised, '');
    }

    public function test_full_lifecycle_open_to_resolved_to_closed(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();
        $service = app(SupportCaseService::class);

        $case = $service->assign($case, $manager, $manager);
        $case = $service->resolve($case, $manager, SupportCaseResolutionType::InformationProvided, 'Explained how it works.');
        $this->assertSame(SupportCaseStatus::Resolved, $case->status);
        $this->assertNotNull($case->resolved_at);
        $this->assertSame('Explained how it works.', $case->resolution_summary);

        $case = $service->close($case, $manager);
        $this->assertSame(SupportCaseStatus::Closed, $case->status);
        $this->assertNotNull($case->closed_at);
    }

    public function test_a_closed_case_cannot_be_resolved_again(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();
        $service = app(SupportCaseService::class);

        $case = $service->resolve($case, $manager, SupportCaseResolutionType::UserAdvised, 'Advised the user.');
        $case = $service->close($case, $manager);

        $this->expectException(InvalidSupportCaseTransitionException::class);
        $service->resolve($case, $manager, SupportCaseResolutionType::UserAdvised, 'Again.');
    }

    public function test_an_open_case_cannot_transition_directly_to_closed(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();

        $this->expectException(InvalidSupportCaseTransitionException::class);
        app(SupportCaseService::class)->close($case, $manager);
    }

    public function test_a_resolved_case_can_be_reopened_by_the_requester(): void
    {
        $student = $this->student();
        $case = $this->openCase($student);
        $manager = $this->manager();
        $service = app(SupportCaseService::class);

        $case = $service->resolve($case, $manager, SupportCaseResolutionType::UserAdvised, 'Advised.');
        $case = $service->reopen($case, $student, 'This did not actually fix it.');

        $this->assertSame(SupportCaseStatus::Open, $case->status);
    }

    public function test_an_open_case_cannot_be_reopened(): void
    {
        $case = $this->openCase();

        $this->expectException(InvalidSupportCaseTransitionException::class);
        app(SupportCaseService::class)->reopen($case, $this->manager());
    }

    public function test_status_transitions_are_audit_logged(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();

        app(SupportCaseService::class)->escalate($case, $manager, 'High-value transaction involved.');

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'support_cases')
                ->where('event', 'case_escalated')
                ->where('subject_id', $case->id)
                ->exists()
        );
    }

    public function test_concurrent_status_updates_do_not_both_succeed_against_a_stale_status(): void
    {
        $case = $this->openCase();
        $manager = $this->manager();
        $service = app(SupportCaseService::class);

        // Two in-memory copies of the same row, simulating two admins
        // acting on the same case at once.
        $copyA = SupportCase::query()->findOrFail($case->id);
        $copyB = SupportCase::query()->findOrFail($case->id);

        $service->escalate($copyA, $manager, 'First admin escalates.');

        // copyB is stale (still "Open" in memory) but the action re-reads
        // the committed row under a lock before validating the guard, so
        // it sees "Escalated" and correctly allows Escalated -> Resolved
        // rather than blindly trusting the stale in-memory Open status.
        $result = $service->resolve($copyB, $manager, SupportCaseResolutionType::UserAdvised, 'Resolved after escalation.');

        $this->assertSame(SupportCaseStatus::Resolved, $result->status);
        $this->assertSame(SupportCaseStatus::Resolved, $case->fresh()->status);
    }
}
