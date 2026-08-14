<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Types\PaidOneToOneType;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\StudentPackageEntitlement;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Package\Services\PackageEntitlementService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * StudentPackageEntitlement: the consumed-value side of the package
 * domain. Covers quantity integrity (including the DB-level
 * guarantees), consumption, and the read-only authorization matrix.
 *
 * Phase 4B.2 changed how an entitlement comes into existence. Accepting
 * a proposal no longer creates one — it creates a PendingPayment
 * StudentPackagePurchase, and the balance is granted only after
 * verified settlement (Phase 4B.3). The tests below therefore create
 * the entitlement explicitly through acceptedEntitlement(), which
 * stands in for that future settlement step rather than pretending
 * acceptance still activates anything. Acceptance's own behaviour is
 * covered by StudentPackagePurchaseTest.
 */
class StudentPackageEntitlementTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole('manager');
    }

    private function proposals(): InstructorPackageProposalService
    {
        return app(InstructorPackageProposalService::class);
    }

    private function entitlements(): PackageEntitlementService
    {
        return app(PackageEntitlementService::class);
    }

    /**
     * Accept the proposal, then grant the balance the way Phase 4B.3's
     * settlement handler will. Deliberately explicit: acceptance alone
     * no longer produces an entitlement, and no test here should imply
     * otherwise.
     */
    private function acceptedEntitlement(InstructorPackageProposal $proposal): StudentPackageEntitlement
    {
        $accepted = $this->proposals()->acceptProposal($proposal, $proposal->student);

        return $this->entitlements()->createFromProposal($accepted);
    }

    /** A Submitted proposal from a genuinely related instructor/student pair. */
    private function submittedProposal(int $paid = 20, int $bonus = 5): InstructorPackageProposal
    {
        $fixture = $this->createPaidBookingTypeWithPrice(PaidOneToOneType::KEY, 20.00, 'GBP');

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');
        $this->assignBillingCountry($student, $fixture['country']);

        Booking::factory()->confirmed()->paid()->create([
            'booking_type_id' => $fixture['type']->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $rule = app(PackageBenefitRuleService::class)->create($this->manager, [
            'name' => "{$paid} paid + {$bonus} bonus",
            'paid_quantity' => $paid,
            'bonus_quantity' => $bonus,
            'total_quantity' => $paid + $bonus,
        ]);

        return $this->proposals()->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: $instructor->id,
            studentId: $student->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $this->seedLessonSubject()->id,
            academicLevelId: null,
        ));
    }

    private function approvedProposal(int $paid = 20, int $bonus = 5): InstructorPackageProposal
    {
        return $this->proposals()->approve($this->submittedProposal($paid, $bonus), $this->manager, null, null);
    }

    // ── Creation (post-settlement, once granted) ──────────────────────────

    public function test_a_granted_entitlement_carries_the_proposals_participants_and_is_active(): void
    {
        $proposal = $this->approvedProposal(20, 5);

        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertSame($proposal->student_id, $entitlement->student_id);
        $this->assertSame($proposal->instructor_id, $entitlement->instructor_id);
        $this->assertSame(PackageEntitlementStatus::Active, $entitlement->status);
        $this->assertNotNull($entitlement->activated_at);
    }

    public function test_entitlement_quantities_mirror_the_proposal(): void
    {
        $proposal = $this->approvedProposal(20, 5);

        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertSame(20, $entitlement->paid_quantity);
        $this->assertSame(5, $entitlement->bonus_quantity);
        $this->assertSame(25, $entitlement->total_quantity);
        $this->assertSame(0, $entitlement->used_quantity);
        $this->assertSame(25, $entitlement->remaining_quantity);
    }

    public function test_a_proposal_that_is_not_approved_cannot_be_accepted(): void
    {
        $proposal = $this->submittedProposal(); // still Submitted, never approved

        try {
            $this->proposals()->acceptProposal($proposal, $proposal->student);
            $this->fail('Expected acceptance of a non-approved proposal to be rejected.');
        } catch (PackageException) {
            // expected
        }

        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_rejected_proposal_cannot_be_accepted(): void
    {
        $rejected = $this->proposals()->reject($this->submittedProposal(), $this->manager, 'Not a fit.');

        try {
            $this->proposals()->acceptProposal($rejected, $rejected->student);
            $this->fail('Expected acceptance of a rejected proposal to be rejected.');
        } catch (PackageException) {
            // expected
        }

        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    // ── Who may accept ────────────────────────────────────────────────────

    public function test_the_target_student_can_accept_their_own_proposal(): void
    {
        $proposal = $this->approvedProposal();

        $this->assertTrue($proposal->student->can('accept', $proposal));
    }

    public function test_another_student_cannot_accept_someone_elses_proposal(): void
    {
        $proposal = $this->approvedProposal();

        $otherStudent = User::factory()->create(['status' => 'active']);
        $otherStudent->assignRole('student');

        $this->assertFalse($otherStudent->can('accept', $proposal));

        // Even bypassing the policy, the service refuses.
        $this->expectException(PackageException::class);
        $this->proposals()->acceptProposal($proposal, $otherStudent);
    }

    public function test_the_instructor_cannot_accept_their_own_proposal(): void
    {
        $proposal = $this->approvedProposal();

        $this->assertFalse($proposal->instructor->can('accept', $proposal));

        $this->expectException(PackageException::class);
        $this->proposals()->acceptProposal($proposal, $proposal->instructor);
    }

    // ── Duplicate acceptance ──────────────────────────────────────────────

    /** The DB is the real guard, independent of any service-level status check. */
    public function test_a_second_entitlement_for_the_same_proposal_is_rejected_at_database_level(): void
    {
        $proposal = $this->approvedProposal();
        $this->acceptedEntitlement($proposal);

        $this->expectException(QueryException::class);
        StudentPackageEntitlement::query()->create([
            'student_id' => $proposal->student_id,
            'instructor_id' => $proposal->instructor_id,
            'proposal_id' => $proposal->id,
            'paid_quantity' => 1,
            'bonus_quantity' => 0,
            'total_quantity' => 1,
        ]);
    }

    // ── Quantity integrity is the database's job ──────────────────────────

    public function test_remaining_quantity_is_database_generated_and_cannot_be_forged(): void
    {
        $proposal = $this->approvedProposal(20, 5);
        $entitlement = $this->acceptedEntitlement($proposal);

        // remaining_quantity is a STORED GENERATED column, so MySQL
        // refuses the write outright rather than accepting a forged
        // balance — the value can only ever be total - used.
        try {
            $entitlement->forceFill(['remaining_quantity' => 999])->save();
            $this->fail('Expected writing to the generated remaining_quantity column to be rejected.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(25, $entitlement->fresh()->remaining_quantity);
    }

    /** Consuming a lesson updates the generated balance without ever writing to it. */
    public function test_remaining_quantity_tracks_used_quantity_automatically(): void
    {
        $proposal = $this->approvedProposal(3, 0);
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertSame(3, $entitlement->remaining_quantity);

        $entitlement = $this->entitlements()->consumeLesson($entitlement);
        $this->assertSame(2, $entitlement->remaining_quantity);

        $entitlement = $this->entitlements()->consumeLesson($entitlement);
        $this->assertSame(1, $entitlement->remaining_quantity);
    }

    public function test_inconsistent_total_quantity_is_rejected_at_database_level(): void
    {
        $proposal = $this->approvedProposal();

        $this->expectException(QueryException::class);
        StudentPackageEntitlement::query()->create([
            'student_id' => $proposal->student_id,
            'instructor_id' => $proposal->instructor_id,
            'proposal_id' => $proposal->id,
            'paid_quantity' => 10,
            'bonus_quantity' => 2,
            'total_quantity' => 99, // != 10 + 2
        ]);
    }

    // ── Consumption ───────────────────────────────────────────────────────

    public function test_consuming_a_lesson_decrements_remaining_and_increments_used(): void
    {
        $proposal = $this->approvedProposal(20, 5);
        $entitlement = $this->acceptedEntitlement($proposal);

        $updated = $this->entitlements()->consumeLesson($entitlement);

        $this->assertSame(1, $updated->used_quantity);
        $this->assertSame(24, $updated->remaining_quantity);
        $this->assertSame(PackageEntitlementStatus::Active, $updated->status);
    }

    public function test_consuming_the_last_lesson_completes_the_entitlement(): void
    {
        $proposal = $this->approvedProposal(2, 0);
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->entitlements()->consumeLesson($entitlement);
        $completed = $this->entitlements()->consumeLesson($entitlement->fresh());

        $this->assertSame(2, $completed->used_quantity);
        $this->assertSame(0, $completed->remaining_quantity);
        $this->assertSame(PackageEntitlementStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_consuming_beyond_the_balance_is_rejected(): void
    {
        $proposal = $this->approvedProposal(1, 0);
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->entitlements()->consumeLesson($entitlement);

        $this->expectException(PackageException::class);
        $this->entitlements()->consumeLesson($entitlement->fresh());
    }

    public function test_has_available_lessons_and_remaining_lessons_reflect_the_balance(): void
    {
        $proposal = $this->approvedProposal(1, 0);
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertTrue($this->entitlements()->hasAvailableLessons($entitlement));
        $this->assertSame(1, $this->entitlements()->remainingLessons($entitlement));

        $consumed = $this->entitlements()->consumeLesson($entitlement);

        $this->assertFalse($this->entitlements()->hasAvailableLessons($consumed));
        $this->assertSame(0, $this->entitlements()->remainingLessons($consumed));
    }

    // ── Student decline ───────────────────────────────────────────────────

    public function test_student_can_decline_an_approved_proposal_without_creating_an_entitlement_or_purchase(): void
    {
        $proposal = $this->approvedProposal();

        $this->assertTrue($proposal->student->can('decline', $proposal));

        $declined = $this->proposals()->declineProposal($proposal, $proposal->student);

        $this->assertSame('cancelled', $declined->status->value);
        $this->assertDatabaseCount('student_package_entitlements', 0);
        $this->assertDatabaseCount('student_package_purchases', 0);
    }

    public function test_another_student_cannot_decline_someone_elses_proposal(): void
    {
        $proposal = $this->approvedProposal();
        $otherStudent = User::factory()->create(['status' => 'active']);
        $otherStudent->assignRole('student');

        $this->assertFalse($otherStudent->can('decline', $proposal));

        $this->expectException(PackageException::class);
        $this->proposals()->declineProposal($proposal, $otherStudent);
    }

    // ── Authorization (read-only for everyone) ────────────────────────────

    public function test_admin_can_view_any_entitlement(): void
    {
        $proposal = $this->approvedProposal();
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertTrue($this->manager->can('viewAny', StudentPackageEntitlement::class));
        $this->assertTrue($this->manager->can('view', $entitlement));
    }

    public function test_student_can_view_their_own_entitlement(): void
    {
        $proposal = $this->approvedProposal();
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertTrue($proposal->student->can('view', $entitlement));
    }

    public function test_student_cannot_view_another_students_entitlement(): void
    {
        $proposal = $this->approvedProposal();
        $entitlement = $this->acceptedEntitlement($proposal);

        $otherStudent = User::factory()->create(['status' => 'active']);
        $otherStudent->assignRole('student');

        $this->assertFalse($otherStudent->can('view', $entitlement));
    }

    public function test_instructor_can_view_only_their_own_students_entitlement(): void
    {
        $proposal = $this->approvedProposal();
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->assertTrue($proposal->instructor->can('view', $entitlement));

        $otherInstructor = User::factory()->create(['status' => 'active']);
        $otherInstructor->assignRole('instructor');
        $this->assertFalse($otherInstructor->can('view', $entitlement));
    }

    public function test_nobody_may_create_update_or_delete_an_entitlement_directly(): void
    {
        $proposal = $this->approvedProposal();
        $entitlement = $this->acceptedEntitlement($proposal);

        foreach ([$this->manager, $proposal->student, $proposal->instructor] as $user) {
            $this->assertFalse($user->can('create', StudentPackageEntitlement::class));
            $this->assertFalse($user->can('update', $entitlement));
            $this->assertFalse($user->can('delete', $entitlement));
        }
    }

    public function test_entitlement_cannot_be_hard_deleted(): void
    {
        $proposal = $this->approvedProposal();
        $entitlement = $this->acceptedEntitlement($proposal);

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $entitlement->delete();
    }
}
