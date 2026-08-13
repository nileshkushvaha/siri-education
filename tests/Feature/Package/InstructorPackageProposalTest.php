<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Types\PaidOneToOneType;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\InstructorPackageProposal;
use App\Models\PackageBenefitRule;
use App\Models\Subject;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class InstructorPackageProposalTest extends TestCase
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

    private function service(): InstructorPackageProposalService
    {
        return app(InstructorPackageProposalService::class);
    }

    private function rule(int $paid = 14, int $bonus = 1): PackageBenefitRule
    {
        return app(PackageBenefitRuleService::class)->create($this->manager, [
            'name' => "{$paid} paid + {$bonus} bonus",
            'paid_quantity' => $paid,
            'bonus_quantity' => $bonus,
            'total_quantity' => $paid + $bonus,
        ]);
    }

    /** @return array{type: BookingType, country: Country, currency: Currency, instructor: User, student: User, subject: Subject} */
    private function relatedInstructorAndStudent(float $unitPrice = 20.00): array
    {
        $fixture = $this->createPaidBookingTypeWithPrice(PaidOneToOneType::KEY, $unitPrice, 'GBP');

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

        return [...$fixture, 'instructor' => $instructor, 'student' => $student, 'subject' => $this->seedLessonSubject()];
    }

    private function submittedProposal(float $unitPrice = 20.00, int $paid = 14, int $bonus = 1): InstructorPackageProposal
    {
        $s = $this->relatedInstructorAndStudent($unitPrice);
        $rule = $this->rule($paid, $bonus);

        return $this->service()->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: $s['instructor']->id,
            studentId: $s['student']->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $s['subject']->id,
            academicLevelId: null,
        ));
    }

    // ── Instructor proposal creation ─────────────────────────────────────

    public function test_instructor_can_create_a_proposal_for_a_related_student(): void
    {
        $s = $this->relatedInstructorAndStudent();
        $rule = $this->rule();

        $proposal = $this->service()->create(new CreatePackageProposalData(
            instructorId: $s['instructor']->id,
            studentId: $s['student']->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $s['subject']->id,
            academicLevelId: null,
        ));

        $this->assertSame('draft', $proposal->status->value);
        $this->assertSame($s['instructor']->id, $proposal->instructor_id);
        $this->assertSame($s['student']->id, $proposal->student_id);
    }

    public function test_unrelated_student_is_rejected(): void
    {
        $fixture = $this->createPaidBookingTypeWithPrice(PaidOneToOneType::KEY, 20.00, 'GBP');
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $unrelatedStudent = User::factory()->create(['status' => 'active']);
        $unrelatedStudent->assignRole('student');
        $this->assignBillingCountry($unrelatedStudent, $fixture['country']);
        // Deliberately no Booking created between them.

        $rule = $this->rule();

        $this->expectException(PackageException::class);
        $this->service()->create(new CreatePackageProposalData(
            instructorId: $instructor->id,
            studentId: $unrelatedStudent->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $this->seedLessonSubject()->id,
            academicLevelId: null,
        ));
    }

    // ── Pricing ───────────────────────────────────────────────────────────

    public function test_calculated_price_is_unit_price_times_paid_quantity(): void
    {
        $s = $this->relatedInstructorAndStudent(20.00);
        $rule = $this->rule(paid: 14, bonus: 1);

        $proposal = $this->service()->create(new CreatePackageProposalData(
            instructorId: $s['instructor']->id,
            studentId: $s['student']->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $s['subject']->id,
            academicLevelId: null,
        ));

        $this->assertSame(2000, $proposal->unit_price_minor); // £20.00
        $this->assertSame(14, $proposal->paid_quantity);
        $this->assertSame(1, $proposal->bonus_quantity);
        $this->assertSame(15, $proposal->total_quantity);
        $this->assertSame(28000, $proposal->calculated_price_minor); // 14 * £20.00 = £280.00
        $this->assertSame(28000, $proposal->final_price_minor); // no override yet
    }

    public function test_admin_override_changes_final_price_but_keeps_calculated_price(): void
    {
        $proposal = $this->submittedProposal(20.00, 14, 1);
        $this->assertSame(28000, $proposal->calculated_price_minor);

        $approved = $this->service()->approve($proposal, $this->manager, 26000, 'Loyalty discount');

        $this->assertSame(28000, $approved->calculated_price_minor); // unchanged
        $this->assertSame(26000, $approved->override_price_minor);
        $this->assertSame(26000, $approved->final_price_minor);
        $this->assertSame($this->manager->id, $approved->overridden_by);
        $this->assertNotNull($approved->overridden_at);
        $this->assertSame('Loyalty discount', $approved->override_reason);
    }

    public function test_override_without_a_reason_is_rejected(): void
    {
        $proposal = $this->submittedProposal();

        $this->expectException(PackageException::class);
        $this->service()->approve($proposal, $this->manager, 26000, '');
    }

    public function test_approval_without_an_override_uses_the_calculated_price(): void
    {
        $proposal = $this->submittedProposal(20.00, 14, 1);

        $approved = $this->service()->approve($proposal, $this->manager, null, null);

        $this->assertNull($approved->override_price_minor);
        $this->assertSame($approved->calculated_price_minor, $approved->final_price_minor);
    }

    public function test_currency_is_locked_and_never_admin_editable(): void
    {
        $proposal = $this->submittedProposal();
        $originalCurrency = $proposal->currency_code;

        $approved = $this->service()->approve($proposal, $this->manager, 26000, 'Discount');

        // No currency parameter exists on approve() at all — the
        // currency locked at submission is the only one that can ever
        // apply to this proposal.
        $this->assertSame($originalCurrency, $approved->currency_code);
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_instructor_cannot_approve_a_proposal(): void
    {
        $proposal = $this->submittedProposal();

        $this->assertFalse($proposal->instructor->can('approve', $proposal));
    }

    public function test_instructor_cannot_reject_a_proposal(): void
    {
        $proposal = $this->submittedProposal();

        $this->assertFalse($proposal->instructor->can('reject', $proposal));
    }

    public function test_instructor_cannot_override_price(): void
    {
        $proposal = $this->submittedProposal();

        $this->assertFalse($proposal->instructor->can('overridePrice', $proposal));
    }

    public function test_manager_can_approve_and_reject(): void
    {
        $proposal = $this->submittedProposal();

        $this->assertTrue($this->manager->can('approve', $proposal));
        $this->assertTrue($this->manager->can('reject', $proposal));
        $this->assertTrue($this->manager->can('overridePrice', $proposal));
    }

    public function test_student_can_only_view_approved_or_accepted_proposals(): void
    {
        $proposal = $this->submittedProposal();

        $this->assertFalse($proposal->student->can('view', $proposal)); // still Submitted

        $approved = $this->service()->approve($proposal, $this->manager, null, null);
        $this->assertTrue($approved->student->can('view', $approved->fresh()));
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function test_submitted_transitions_to_approved(): void
    {
        $proposal = $this->submittedProposal();

        $approved = $this->service()->approve($proposal, $this->manager, null, null);

        $this->assertSame('approved', $approved->status->value);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_submitted_transitions_to_rejected(): void
    {
        $proposal = $this->submittedProposal();

        $rejected = $this->service()->reject($proposal, $this->manager, 'Not a good fit right now.');

        $this->assertSame('rejected', $rejected->status->value);
        $this->assertSame('Not a good fit right now.', $rejected->rejection_reason);
    }

    public function test_accepted_proposal_is_immutable(): void
    {
        $proposal = $this->submittedProposal();
        $approved = $this->service()->approve($proposal, $this->manager, null, null);
        $accepted = $this->service()->acceptProposal($approved, $approved->student);

        $this->assertSame('accepted', $accepted->status->value);

        $this->expectException(ImmutableRecordCannotBeUpdatedException::class);
        $accepted->update(['override_reason' => 'tampering attempt']);
    }

    public function test_draft_can_be_recalculated(): void
    {
        $s = $this->relatedInstructorAndStudent(20.00);
        $rule = $this->rule();

        $proposal = $this->service()->create(new CreatePackageProposalData(
            instructorId: $s['instructor']->id,
            studentId: $s['student']->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $s['subject']->id,
            academicLevelId: null,
        ));

        $recalculated = $this->service()->recalculate($proposal);

        $this->assertSame($proposal->calculated_price_minor, $recalculated->calculated_price_minor);
    }
}
