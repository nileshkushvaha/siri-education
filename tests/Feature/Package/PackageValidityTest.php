<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Types\PaidOneToOneType;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\PackageBenefitRule;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Package\Services\PackageEntitlementService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4B.1 — package VALIDITY (entitlement usage window), which is a
 * distinct concept from payment-attempt expiry and from offer-acceptance
 * expiry. Admin configures it on the offer; it is snapshotted onto the
 * proposal; the absolute `expires_at` is deliberately NOT computed yet
 * (that belongs to activation-after-payment in Phase 4B.3).
 */
class PackageValidityTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $manager;

    /** @var array{instructor: User, student: User, subjectId: string}|null */
    private ?array $pair = null;

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

    private function rules(): PackageBenefitRuleService
    {
        return app(PackageBenefitRuleService::class);
    }

    private function proposals(): InstructorPackageProposalService
    {
        return app(InstructorPackageProposalService::class);
    }

    private function rule(?int $validityDays = 90): PackageBenefitRule
    {
        return $this->rules()->create($this->manager, [
            'name' => 'Validity rule',
            'paid_quantity' => 20,
            'bonus_quantity' => 5,
            'total_quantity' => 25,
            'validity_days' => $validityDays,
        ]);
    }

    /**
     * Memoized per test: the paid booking type has a unique `key`, so
     * rebuilding the fixture for a second proposal would collide. Reusing
     * one instructor/student pair also mirrors reality — the same pair
     * can legitimately receive several offers over time.
     *
     * @return array{instructor: User, student: User, subjectId: string}
     */
    private function relatedPair(): array
    {
        if ($this->pair !== null) {
            return $this->pair;
        }

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

        return $this->pair = ['instructor' => $instructor, 'student' => $student, 'subjectId' => $this->seedLessonSubject()->id];
    }

    private function proposalFor(PackageBenefitRule $rule): InstructorPackageProposal
    {
        $pair = $this->relatedPair();

        return $this->proposals()->create(new CreatePackageProposalData(
            instructorId: $pair['instructor']->id,
            studentId: $pair['student']->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $pair['subjectId'],
            academicLevelId: null,
        ));
    }

    // ── 11. Admin can configure validity ──────────────────────────────────

    public function test_admin_can_configure_package_validity_days(): void
    {
        $rule = $this->rule(90);

        $this->assertSame(90, $rule->validity_days);
        $this->assertDatabaseHas('package_benefit_rules', ['id' => $rule->id, 'validity_days' => 90]);
    }

    public function test_admin_can_update_package_validity_days(): void
    {
        $rule = $this->rule(90);

        $updated = $this->rules()->update($this->manager, $rule, ['validity_days' => 60]);

        $this->assertSame(60, $updated->validity_days);
    }

    // ── 12. Instructor cannot set or override validity ────────────────────

    public function test_instructor_cannot_set_validity_via_the_proposal_dto(): void
    {
        // The instructor-facing DTO has no validity field at all — the
        // only source is the admin-owned offer. This is a structural
        // guarantee, not a runtime check.
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(CreatePackageProposalData::class))->getProperties(),
        );

        $this->assertNotContains('validityDays', $properties);
        $this->assertNotContains('validity_days', $properties);
    }

    public function test_instructor_cannot_update_a_package_offer(): void
    {
        $rule = $this->rule(90);

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $this->expectException(AuthorizationException::class);
        $this->rules()->update($instructor, $rule, ['validity_days' => 3650]);
    }

    // ── 13/14/15. Snapshot semantics ──────────────────────────────────────

    public function test_proposal_snapshots_validity_from_the_offer(): void
    {
        $proposal = $this->proposalFor($this->rule(90));

        $this->assertSame(90, $proposal->validity_days);
    }

    public function test_editing_the_offer_later_does_not_change_an_existing_proposal(): void
    {
        $rule = $this->rule(90);
        $proposal = $this->proposalFor($rule);
        $this->assertSame(90, $proposal->validity_days);

        $this->rules()->update($this->manager, $rule, ['validity_days' => 60]);

        $this->assertSame(90, $proposal->fresh()->validity_days);
    }

    public function test_new_proposals_use_the_updated_offer_validity(): void
    {
        $rule = $this->rule(90);
        $this->proposalFor($rule);

        $this->rules()->update($this->manager, $rule, ['validity_days' => 60]);

        $newProposal = $this->proposalFor($rule->fresh());

        $this->assertSame(60, $newProposal->validity_days);
    }

    // ── 16. NULL means no expiry ──────────────────────────────────────────

    public function test_null_validity_is_preserved_and_means_no_expiry(): void
    {
        $rule = $this->rule(null);
        $this->assertNull($rule->validity_days);

        $proposal = $this->proposalFor($rule);
        $this->assertNull($proposal->validity_days);
    }

    // ── 17. Invalid values rejected ───────────────────────────────────────

    public function test_zero_validity_is_rejected_rather_than_meaning_unlimited(): void
    {
        $this->expectException(PackageException::class);
        $this->rule(0);
    }

    public function test_negative_validity_is_rejected(): void
    {
        $this->expectException(PackageException::class);
        $this->rule(-30);
    }

    public function test_zero_validity_is_rejected_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        PackageBenefitRule::query()->create([
            'name' => 'Direct zero validity',
            'paid_quantity' => 1,
            'bonus_quantity' => 0,
            'total_quantity' => 1,
            'validity_days' => 0,
        ]);
    }

    // ── 18. Entitlement supports expiry but nothing auto-expires yet ──────

    /**
     * Phase 4B.2 regression: acceptance creates a PendingPayment
     * purchase and NO entitlement, so there is nothing yet for an
     * expiry to apply to — while the validity snapshot itself survives
     * the acceptance untouched.
     */
    public function test_acceptance_creates_no_entitlement_and_leaves_the_validity_snapshot_intact(): void
    {
        $rule = $this->rule(90);
        $accepted = $this->acceptApproved($rule);

        $this->assertSame(90, $accepted->validity_days);
        $this->assertDatabaseCount('student_package_entitlements', 0);
        $this->assertDatabaseHas('student_package_purchases', [
            'proposal_id' => $accepted->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_a_granted_entitlement_carries_validity_but_no_absolute_expiry_is_computed_yet(): void
    {
        $rule = $this->rule(90);
        $accepted = $this->acceptApproved($rule);

        // Stands in for Phase 4B.3's settlement step — the only thing
        // that will ever grant a balance.
        $entitlement = app(PackageEntitlementService::class)->createFromProposal($accepted);

        // Validity travels forward…
        $this->assertSame(90, $entitlement->validity_days);
        // …but the absolute instant is still deliberately NOT set, and
        // no scheduler flips Active -> Expired.
        $this->assertNull($entitlement->expires_at);
        $this->assertSame('active', $entitlement->status->value);
    }

    /** Submit -> approve -> accept, returning the accepted proposal. */
    private function acceptApproved(PackageBenefitRule $rule): InstructorPackageProposal
    {
        $proposal = $this->proposals()->approve(
            $this->proposals()->submit($this->proposalFor($rule), $this->proposalInstructor($rule)),
            $this->manager,
            null,
            null,
        );

        return $this->proposals()->acceptProposal($proposal, $proposal->student);
    }

    /** The proposal's own instructor — submit() requires the acting instructor. */
    private function proposalInstructor(PackageBenefitRule $rule): User
    {
        return InstructorPackageProposal::query()
            ->where('package_benefit_rule_id', $rule->id)
            ->latest('created_at')
            ->firstOrFail()
            ->instructor;
    }
}
