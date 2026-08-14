<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\PackageAcademicContext;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Services\PackageBookingEntitlementResolver;
use App\Package\Services\PackageEntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4D — which packages may fund a booking (spec Part 43) and the
 * authorization around that choice (Part 49).
 *
 * Two properties dominate:
 *
 *  1. Matching is on the package's FROZEN structured identity, by stable
 *     id. Wrong education system, wrong level, wrong subject, wrong
 *     curriculum VERSION — each on its own disqualifies. A legacy
 *     entitlement with no frozen context is never fuzzy-matched in.
 *  2. The resolver never CHOOSES. Multiple qualifying packages are all
 *     returned; nothing about ordering, expiry or balance is allowed to
 *     collapse them into an automatic pick.
 *
 * These build entitlements directly against synthetic frozen contexts
 * rather than driving the whole proposal→payment pipeline: the pipeline
 * has its own suites, and the subject here is purely the matching rule.
 */
class PackageBookingEntitlementResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function resolver(): PackageBookingEntitlementResolver
    {
        return app(PackageBookingEntitlementResolver::class);
    }

    private function entitlements(): PackageEntitlementService
    {
        return app(PackageEntitlementService::class);
    }

    /** The canonical context every "matching" case is compared against. */
    private function context(array $overrides = []): BookingAcademicContextData
    {
        return new BookingAcademicContextData(
            countryId: $overrides['countryId'] ?? 1,
            countryCode: 'IN',
            countryName: 'India',
            educationSystemId: $overrides['educationSystemId'] ?? 'sys-cbse',
            educationSystemCode: 'CBSE',
            educationSystemName: 'CBSE',
            academicLevelId: 'lvl-band',
            academicLevelName: 'Secondary',
            educationSystemLevelId: $overrides['educationSystemLevelId'] ?? 'esl-class-10',
            levelTerm: 'Class',
            levelValue: '10',
            levelDisplay: 'Class 10',
            normalizedGrade: 10,
            subjectId: $overrides['subjectId'] ?? 'sub-maths',
            subjectName: 'Mathematics',
            subjectSlug: 'mathematics',
            curriculumId: $overrides['curriculumId'] ?? 'cur-maths-10',
            curriculumName: 'CBSE Mathematics Class 10',
            curriculumSlug: 'cbse-maths-10',
            curriculumVersionId: $overrides['curriculumVersionId'] ?? 'ver-2',
            curriculumVersionNumber: 2,
        );
    }

    private function student(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('student');

        return $user;
    }

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('instructor');

        return $user;
    }

    /**
     * An Active, settled entitlement whose proposal carries a frozen
     * context built from $contextOverrides.
     */
    private function package(
        User $student,
        User $instructor,
        array $contextOverrides = [],
        int $total = 5,
        ?string $expiresAt = '2027-12-31 00:00:00',
        PackageEntitlementStatus $status = PackageEntitlementStatus::Active,
        bool $settled = true,
        bool $withContext = true,
    ): StudentPackageEntitlement {
        $context = $this->context($contextOverrides);

        return StudentPackageEntitlement::withoutEvents(function () use ($student, $instructor, $context, $total, $expiresAt, $status, $settled, $withContext) {
            Schema::disableForeignKeyConstraints();

            $proposal = InstructorPackageProposal::query()->create([
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'subject_id' => $context->subjectId,
                'paid_quantity' => $total,
                'bonus_quantity' => 0,
                'total_quantity' => $total,
                'currency_code' => 'INR',
                'calculated_price_minor' => 1000,
                'final_price_minor' => 1000,
                'status' => 'accepted',
            ]);

            if ($withContext) {
                PackageAcademicContext::query()->create([
                    'proposal_id' => $proposal->id,
                    'country_id' => $context->countryId,
                    'country_code' => $context->countryCode,
                    'country_name' => $context->countryName,
                    'education_system_id' => $context->educationSystemId,
                    'education_system_name' => $context->educationSystemName,
                    'academic_level_id' => $context->academicLevelId,
                    'academic_level_name' => $context->academicLevelName,
                    'education_system_level_id' => $context->educationSystemLevelId,
                    'level_term' => $context->levelTerm,
                    'level_value' => $context->levelValue,
                    'level_display' => $context->levelDisplay,
                    'normalized_grade' => $context->normalizedGrade,
                    'subject_id' => $context->subjectId,
                    'subject_name' => $context->subjectName,
                    'curriculum_id' => $context->curriculumId,
                    'curriculum_name' => $context->curriculumName,
                    'curriculum_version_id' => $context->curriculumVersionId,
                    'curriculum_version_number' => $context->curriculumVersionNumber,
                ]);
            }

            if ($settled) {
                StudentPackagePurchase::query()->create([
                    'proposal_id' => $proposal->id,
                    'student_id' => $student->id,
                    'reference' => 'PKG-'.Str::upper(Str::random(8)),
                    'amount_minor' => 1000,
                    'currency_code' => 'INR',
                    'status' => PackagePurchaseStatus::Paid,
                    'paid_at' => now(),
                ]);
            }

            $row = StudentPackageEntitlement::query()->create([
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
                'proposal_id' => $proposal->id,
                'subject_id' => $context->subjectId,
                'paid_quantity' => $total,
                'bonus_quantity' => 0,
                'total_quantity' => $total,
                'used_quantity' => 0,
                'status' => $status,
                'validity_days' => $expiresAt === null ? null : 90,
                'activated_at' => now()->subDay(),
                'expires_at' => $expiresAt,
            ]);

            Schema::enableForeignKeyConstraints();

            return $row->refresh();
        });
    }

    // ── 18-20. Ownership ──────────────────────────────────────────────────

    public function test_a_matching_package_is_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $entitlement = $this->package($student, $instructor);

        $eligible = $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context());

        $this->assertCount(1, $eligible);
        $this->assertSame($entitlement->id, $eligible->first()->id);
    }

    public function test_another_students_package_is_never_offered(): void
    {
        $owner = $this->student();
        $otherStudent = $this->student();
        $instructor = $this->instructor();
        $this->package($owner, $instructor);

        $this->assertCount(0, $this->resolver()->eligibleFor($otherStudent, (int) $instructor->id, $this->context()));
    }

    public function test_another_instructors_package_is_never_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $otherInstructor = $this->instructor();
        $this->package($student, $instructor);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $otherInstructor->id, $this->context()));
    }

    // ── 21-23. Structured identity must match exactly ─────────────────────

    public function test_a_wrong_subject_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, ['subjectId' => 'sub-physics']);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_wrong_education_system_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, ['educationSystemId' => 'sys-icse']);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_wrong_education_system_level_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, ['educationSystemLevelId' => 'esl-class-9']);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_wrong_curriculum_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, ['curriculumId' => 'cur-other']);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_package_frozen_on_a_different_curriculum_version_is_not_offered(): void
    {
        // The booking context is v2; a package sold under v1 is a
        // different academic product and must not silently fund it.
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, ['curriculumVersionId' => 'ver-1']);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_legacy_package_with_no_frozen_context_is_never_fuzzy_matched(): void
    {
        // Same student, instructor and subject — matches on everything
        // the legacy shape knows about. It must STILL be excluded,
        // because it has no structured identity to match on. Fail closed.
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, withContext: false);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    // ── 24-27. State and capacity ─────────────────────────────────────────

    public function test_an_expired_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, expiresAt: now()->subDay()->toDateTimeString());

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_completed_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, status: PackageEntitlementStatus::Completed);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_cancelled_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, status: PackageEntitlementStatus::Cancelled);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_an_unsettled_package_is_not_offered(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, settled: false);

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    public function test_a_package_with_no_available_to_book_capacity_is_not_offered(): void
    {
        // Balance remains (nothing consumed) but the single unit is
        // already committed to a scheduled booking — availability, not
        // remaining_quantity, is what gates the offer.
        $student = $this->student();
        $instructor = $this->instructor();
        $entitlement = $this->package($student, $instructor, total: 1);

        $booking = Booking::factory()->confirmed()->paid()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'package_entitlement_id' => $entitlement->id,
        ]);
        $this->entitlements()->reserveForBooking($entitlement, $booking);

        $this->assertSame(1, (int) $entitlement->refresh()->remaining_quantity);
        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    // ── 28-29. Multiple packages, no automatic choice ────────────────────

    public function test_multiple_compatible_packages_are_all_returned_separately(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();

        $small = $this->package($student, $instructor, total: 2, expiresAt: '2026-12-20 00:00:00');
        $large = $this->package($student, $instructor, total: 10, expiresAt: '2027-02-20 00:00:00');

        $eligible = $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context());

        $this->assertCount(2, $eligible);
        $this->assertEqualsCanonicalizing(
            [$small->id, $large->id],
            $eligible->pluck('id')->all(),
        );
    }

    public function test_the_resolver_never_narrows_multiple_packages_to_a_preference(): void
    {
        // Three packages differing on exactly the axes a FIFO/FEFO/
        // largest-balance heuristic would key on. All three must survive:
        // the student's explicit choice is what resolves the ambiguity.
        $student = $this->student();
        $instructor = $this->instructor();

        $this->package($student, $instructor, total: 1, expiresAt: '2026-11-01 00:00:00');
        $this->package($student, $instructor, total: 20, expiresAt: '2027-06-01 00:00:00');
        $this->package($student, $instructor, total: 5, expiresAt: null);

        $this->assertCount(3, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context()));
    }

    // ── 26, 44-50. Expiry vs. scheduled lesson end ───────────────────────

    public function test_a_slot_finishing_inside_the_validity_window_is_allowed(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, expiresAt: '2027-01-28 23:59:59');

        $endsAt = CarbonImmutable::parse('2027-01-28 10:00:00', 'UTC');

        $this->assertCount(1, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context(), $endsAt));
    }

    public function test_a_slot_finishing_after_expiry_is_not_offered(): void
    {
        // The spec's midnight-crossover case: a 23:30 start with a
        // 60-minute lesson against a 00:00 expiry finishes too late.
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, expiresAt: '2027-01-29 00:00:00');

        $endsAt = CarbonImmutable::parse('2027-01-29 00:30:00', 'UTC');

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context(), $endsAt));
    }

    public function test_expiry_is_compared_as_an_absolute_instant_not_local_wall_clock(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, expiresAt: '2027-01-29 00:00:00');

        // 20:30 in New York on the 28th IS 01:30 UTC on the 29th — past
        // expiry. A wall-clock comparison would wrongly allow it.
        $endsAt = CarbonImmutable::parse('2027-01-28 20:30:00', 'America/New_York');

        $this->assertCount(0, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context(), $endsAt));
    }

    public function test_a_null_expiry_imposes_no_slot_restriction(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor, expiresAt: null);

        $endsAt = CarbonImmutable::parse('2099-01-01 00:00:00', 'UTC');

        $this->assertCount(1, $this->resolver()->eligibleFor($student, (int) $instructor->id, $this->context(), $endsAt));
    }

    // ── 71-73. Server-side revalidation of a posted id ───────────────────

    public function test_is_eligible_confirms_a_legitimately_selected_package(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $entitlement = $this->package($student, $instructor);

        $this->assertTrue(
            $this->resolver()->isEligible($student, (int) $instructor->id, $this->context(), (string) $entitlement->id),
        );
    }

    public function test_a_forged_entitlement_uuid_is_rejected(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->package($student, $instructor);

        $this->assertFalse(
            $this->resolver()->isEligible($student, (int) $instructor->id, $this->context(), Str::uuid()->toString()),
        );
    }

    public function test_another_students_entitlement_id_is_rejected_server_side(): void
    {
        $owner = $this->student();
        $attacker = $this->student();
        $instructor = $this->instructor();
        $entitlement = $this->package($owner, $instructor);

        // The attacker knows a real, currently-valid UUID — ownership is
        // what stops them, not obscurity.
        $this->assertFalse(
            $this->resolver()->isEligible($attacker, (int) $instructor->id, $this->context(), (string) $entitlement->id),
        );
    }

    public function test_a_valid_entitlement_for_a_different_instructor_is_rejected(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $otherInstructor = $this->instructor();
        $entitlement = $this->package($student, $instructor);

        $this->assertFalse(
            $this->resolver()->isEligible($student, (int) $otherInstructor->id, $this->context(), (string) $entitlement->id),
        );
    }
}
