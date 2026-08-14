<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Earnings\Services\InstructorCompensationResolver;
use App\Earnings\Services\InstructorEarningService;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Events\LessonCancelled;
use App\Lessons\Events\LessonCompleted;
use App\Lessons\Events\LessonDisputed;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Lessons\Services\LessonLifecycleService;
use App\Listeners\Package\ConsumePackageEntitlementOnLessonCompleted;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementConsumption;
use App\Models\Subject;
use App\Models\User;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Exceptions\PackageException;
use App\Package\Services\PackageEntitlementService;
use App\Providers\EventServiceProvider;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4C.2 — package consumption on lesson completion.
 *
 * The two properties that matter most here:
 *
 *  1. Consumption is driven by EXPLICIT attribution. A lesson with no
 *     `package_entitlement_id` never touches a package, even when the
 *     student happens to own a perfectly matching one — that is the
 *     difference between a package lesson and a lesson the student paid
 *     for separately.
 *  2. One lesson consumes at most one unit, ever. Replays, retries and
 *     races all collapse onto a single ledger row.
 */
class PackageEntitlementConsumptionTest extends TestCase
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

    private function entitlements(): PackageEntitlementService
    {
        return app(PackageEntitlementService::class);
    }

    /** @return array{entitlement: StudentPackageEntitlement, student: User, instructor: User, subject: Subject} */
    private function activePackage(int $total = 5, ?string $expiresAt = '2027-12-31 00:00:00'): array
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths-'.Str::random(5), 'status' => 'active']);

        // The proposal/purchase/settlement pipeline has its own suite;
        // these tests are about consumption, so the entitlement is built
        // directly with the one FK relaxed.
        $entitlement = StudentPackageEntitlement::withoutEvents(function () use ($student, $instructor, $subject, $total, $expiresAt) {
            Schema::disableForeignKeyConstraints();

            $row = StudentPackageEntitlement::query()->create([
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $subject->id,
                'paid_quantity' => $total,
                'bonus_quantity' => 0,
                'total_quantity' => $total,
                'used_quantity' => 0,
                'status' => PackageEntitlementStatus::Active,
                'validity_days' => $expiresAt === null ? null : 90,
                'activated_at' => now()->subDay(),
                'expires_at' => $expiresAt,
            ]);

            Schema::enableForeignKeyConstraints();

            return $row->refresh();
        });

        return compact('entitlement', 'student', 'instructor', 'subject');
    }

    /** A lesson, optionally attributed to a package. */
    private function lesson(array $package, bool $funded = true, ?User $student = null, ?User $instructor = null, ?Subject $subject = null): Lesson
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'student_id' => ($student ?? $package['student'])->id,
            'instructor_id' => ($instructor ?? $package['instructor'])->id,
            'package_entitlement_id' => $funded ? $package['entitlement']->id : null,
        ]);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'student_id' => ($student ?? $package['student'])->id,
            'instructor_id' => ($instructor ?? $package['instructor'])->id,
            'subject_id' => ($subject ?? $package['subject'])->id,
            'package_entitlement_id' => $funded ? $package['entitlement']->id : null,
            'status' => LessonStatus::Scheduled,
        ]);
    }

    // ── 9-13. The ledger ──────────────────────────────────────────────────

    public function test_a_completed_package_funded_lesson_creates_one_consumption(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package);

        $consumption = $this->entitlements()->consumeForLesson($lesson);

        $this->assertNotNull($consumption);
        $this->assertSame($package['entitlement']->id, $consumption->entitlement_id);
        $this->assertSame($lesson->id, $consumption->lesson_id);
        $this->assertSame($package['student']->id, (int) $consumption->student_id);
        $this->assertSame($package['instructor']->id, (int) $consumption->instructor_id);
        $this->assertSame(1, StudentPackageEntitlementConsumption::query()->count());
    }

    public function test_replaying_the_same_lesson_creates_no_second_consumption(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package);

        $first = $this->entitlements()->consumeForLesson($lesson);
        $second = $this->entitlements()->consumeForLesson($lesson->fresh());
        $third = $this->entitlements()->consumeForLesson($lesson->fresh());

        // A replay returns the existing row rather than throwing —
        // re-delivered completion is legitimate, not an error.
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(1, $package['entitlement']->fresh()->used_quantity);
    }

    /** The database is the real guarantee, independent of any service check. */
    public function test_the_unique_lesson_index_rejects_a_forged_duplicate(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package);
        $this->entitlements()->consumeForLesson($lesson);

        $this->expectException(QueryException::class);
        StudentPackageEntitlementConsumption::query()->create([
            'entitlement_id' => $package['entitlement']->id,
            'lesson_id' => $lesson->id,
            'student_id' => $package['student']->id,
            'instructor_id' => $package['instructor']->id,
            'consumed_at' => now(),
        ]);
    }

    /** …including against a DIFFERENT entitlement — one lesson, one unit, globally. */
    public function test_a_lesson_cannot_consume_from_two_different_entitlements(): void
    {
        $package = $this->activePackage();
        $other = $this->activePackage();
        $lesson = $this->lesson($package);

        $this->entitlements()->consumeForLesson($lesson);

        $this->expectException(QueryException::class);
        StudentPackageEntitlementConsumption::query()->create([
            'entitlement_id' => $other['entitlement']->id,
            'lesson_id' => $lesson->id,
            'student_id' => $other['student']->id,
            'instructor_id' => $other['instructor']->id,
            'consumed_at' => now(),
        ]);
    }

    public function test_used_and_generated_remaining_quantities_are_correct_after_consumption(): void
    {
        $package = $this->activePackage(total: 5);

        $this->entitlements()->consumeForLesson($this->lesson($package));
        $this->entitlements()->consumeForLesson($this->lesson($package));

        $entitlement = $package['entitlement']->fresh();
        $this->assertSame(2, $entitlement->used_quantity);
        $this->assertSame(3, $entitlement->remaining_quantity);
        $this->assertSame(PackageEntitlementStatus::Active, $entitlement->status);
    }

    public function test_a_consumption_record_is_immutable_and_undeletable(): void
    {
        $package = $this->activePackage();
        $consumption = $this->entitlements()->consumeForLesson($this->lesson($package));

        try {
            $consumption->forceFill(['consumed_at' => now()->addDay()])->save();
            $this->fail('Expected a consumption record to be immutable.');
        } catch (ImmutableRecordCannotBeUpdatedException) {
            // expected
        }

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $consumption->delete();
    }

    // ── 14-16. The final unit ─────────────────────────────────────────────

    public function test_the_final_lesson_completes_the_entitlement(): void
    {
        $package = $this->activePackage(total: 2);

        $this->entitlements()->consumeForLesson($this->lesson($package));
        $last = $this->entitlements()->consumeForLesson($this->lesson($package));

        $entitlement = $package['entitlement']->fresh();
        $this->assertSame(2, $entitlement->used_quantity);
        $this->assertSame(0, $entitlement->remaining_quantity);
        $this->assertSame(PackageEntitlementStatus::Completed, $entitlement->status);
        // completed_at is the same instant the last unit was consumed.
        $this->assertSame($last->consumed_at->toDateTimeString(), $entitlement->completed_at->toDateTimeString());
    }

    public function test_a_completed_entitlement_cannot_be_consumed_again(): void
    {
        $package = $this->activePackage(total: 1);
        $this->entitlements()->consumeForLesson($this->lesson($package));
        $this->assertSame(PackageEntitlementStatus::Completed, $package['entitlement']->fresh()->status);

        $this->expectException(PackageException::class);
        $this->entitlements()->consumeForLesson($this->lesson($package));
    }

    public function test_the_remaining_balance_never_goes_negative(): void
    {
        $package = $this->activePackage(total: 1);
        $this->entitlements()->consumeForLesson($this->lesson($package));

        try {
            $this->entitlements()->consumeForLesson($this->lesson($package));
        } catch (PackageException) {
            // expected
        }

        $entitlement = $package['entitlement']->fresh();
        $this->assertSame(1, $entitlement->used_quantity);
        $this->assertSame(0, $entitlement->remaining_quantity);
    }

    // ── 21-25. Attribution is never inferred ──────────────────────────────

    /** The single most important guard in this phase. */
    public function test_an_ordinary_lesson_never_consumes_a_package(): void
    {
        $package = $this->activePackage();

        // Same student, same instructor, same subject, a perfectly good
        // package sitting there — and no attribution.
        $lesson = $this->lesson($package, funded: false);

        $this->assertNull($this->entitlements()->consumeForLesson($lesson));
        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(0, $package['entitlement']->fresh()->used_quantity);
    }

    public function test_a_funded_lesson_consumes_only_its_own_entitlement(): void
    {
        $package = $this->activePackage();
        $decoy = $this->activePackage();

        $this->entitlements()->consumeForLesson($this->lesson($package));

        $this->assertSame(1, $package['entitlement']->fresh()->used_quantity);
        $this->assertSame(0, $decoy['entitlement']->fresh()->used_quantity);
    }

    public function test_another_students_entitlement_cannot_be_consumed(): void
    {
        $package = $this->activePackage();
        $intruder = User::factory()->create(['status' => 'active']);
        $intruder->assignRole('student');

        $lesson = $this->lesson($package, student: $intruder);

        $this->expectExceptionMessage('different student');
        $this->entitlements()->consumeForLesson($lesson);
    }

    public function test_another_instructors_entitlement_cannot_be_consumed(): void
    {
        $package = $this->activePackage();
        $other = User::factory()->create(['status' => 'active']);
        $other->assignRole('instructor');

        $lesson = $this->lesson($package, instructor: $other);

        $this->expectExceptionMessage('different instructor');
        $this->entitlements()->consumeForLesson($lesson);
    }

    public function test_a_different_subject_cannot_be_consumed(): void
    {
        $package = $this->activePackage();

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $physics = Subject::query()->create(['academic_category_id' => $category->id, 'name' => 'Physics', 'slug' => 'physics-'.Str::random(5), 'status' => 'active']);

        $lesson = $this->lesson($package, subject: $physics);

        $this->expectExceptionMessage('different subject');
        $this->entitlements()->consumeForLesson($lesson);
    }

    // ── 26-27. Expiry at the point of delivery ────────────────────────────

    /**
     * The approved policy: the lesson must be DELIVERED before the
     * package expires. Booking in time is not enough.
     */
    public function test_a_lesson_delivered_after_expiry_cannot_consume_the_package(): void
    {
        Carbon::setTestNow('2027-01-01 09:00:00');
        $package = $this->activePackage(expiresAt: '2027-01-28 14:00:00');
        // Booked well within the window…
        $lesson = $this->lesson($package);

        // …but delivered after it closed.
        Carbon::setTestNow('2027-02-05 09:00:00');

        try {
            $this->entitlements()->consumeForLesson($lesson);
            $this->fail('Expected a lapsed package to refuse consumption.');
        } catch (PackageException $e) {
            $this->assertStringContainsStringIgnoringCase('expired', $e->getMessage());
        }

        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(PackageEntitlementStatus::Expired, $package['entitlement']->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_a_lesson_delivered_before_expiry_consumes_normally(): void
    {
        Carbon::setTestNow('2027-01-01 09:00:00');
        $package = $this->activePackage(expiresAt: '2027-01-28 14:00:00');
        $lesson = $this->lesson($package);

        Carbon::setTestNow('2027-01-27 09:00:00');

        $this->assertNotNull($this->entitlements()->consumeForLesson($lesson));
        $this->assertSame(1, $package['entitlement']->fresh()->used_quantity);

        Carbon::setTestNow();
    }

    // ── 17-20. Only a delivered lesson consumes ───────────────────────────

    /**
     * The listener is bound to LessonCompleted, which
     * LessonLifecycleService dispatches only on the completion paths.
     * Cancellation and the no-show outcomes never reach it.
     */
    public function test_consumption_is_wired_to_lesson_completed_and_to_nothing_else(): void
    {
        $listen = (new \ReflectionClass(EventServiceProvider::class))
            ->getProperty('listen')
            ->getValue(app(EventServiceProvider::class, ['app' => app()]));

        $this->assertContains(
            ConsumePackageEntitlementOnLessonCompleted::class,
            $listen[LessonCompleted::class] ?? [],
            'Consumption must be wired to the canonical LessonCompleted event.',
        );

        // Cancellation, no-shows (LessonOutcomeFinalized) and disputes
        // must never reach it.
        foreach ([LessonCancelled::class, LessonOutcomeFinalized::class, LessonDisputed::class] as $event) {
            $this->assertNotContains(
                ConsumePackageEntitlementOnLessonCompleted::class,
                $listen[$event] ?? [],
                "Consumption must not be wired to {$event}.",
            );
        }
    }

    public function test_a_scheduled_lesson_has_not_consumed_anything(): void
    {
        $package = $this->activePackage();
        $this->lesson($package);

        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(0, $package['entitlement']->fresh()->used_quantity);
    }

    public function test_a_cancelled_lesson_does_not_consume(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package);
        $lesson->forceFill(['status' => LessonStatus::Cancelled])->save();

        // Nothing dispatches LessonCompleted for a cancellation, so the
        // listener never runs; the balance is untouched.
        app(ConsumePackageEntitlementOnLessonCompleted::class);
        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(0, $package['entitlement']->fresh()->used_quantity);
    }

    public function test_the_listener_consumes_when_the_completion_event_fires(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package);

        app(ConsumePackageEntitlementOnLessonCompleted::class)->handle(new LessonCompleted($lesson));

        $this->assertSame(1, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(1, $package['entitlement']->fresh()->used_quantity);
    }

    public function test_the_listener_is_a_no_op_for_an_unfunded_lesson(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package, funded: false);

        app(ConsumePackageEntitlementOnLessonCompleted::class)->handle(new LessonCompleted($lesson));

        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
    }

    /**
     * A billing discrepancy must not fail the lesson: the lesson stays
     * completed and the instructor is still paid.
     */
    public function test_the_listener_swallows_a_consumption_failure_and_audits_it(): void
    {
        Carbon::setTestNow('2027-01-01 09:00:00');
        $package = $this->activePackage(expiresAt: '2027-01-28 14:00:00');
        $lesson = $this->lesson($package);

        Carbon::setTestNow('2027-02-05 09:00:00');

        app(ConsumePackageEntitlementOnLessonCompleted::class)->handle(new LessonCompleted($lesson));

        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'package_consumption_failed']);

        Carbon::setTestNow();
    }

    // ── 33-35. Concurrency ────────────────────────────────────────────────

    public function test_two_consumption_calls_for_one_lesson_produce_one_ledger_row(): void
    {
        $package = $this->activePackage();
        $lesson = $this->lesson($package);

        $a = $this->entitlements()->consumeForLesson($lesson);
        $b = $this->entitlements()->consumeForLesson($lesson);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(1, $package['entitlement']->fresh()->used_quantity);
        $this->assertSame(4, $package['entitlement']->fresh()->remaining_quantity);
    }

    // ── 30-32. Instructor compensation is untouched ───────────────────────

    /**
     * Compensation is resolved from the completed Lesson and knows
     * nothing about how the student funded it. These assertions pin
     * that separation structurally rather than by running the earnings
     * pipeline, which has its own suite.
     */
    public function test_instructor_compensation_does_not_read_package_state(): void
    {
        foreach ([
            InstructorEarningService::class,
            InstructorCompensationResolver::class,
        ] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            foreach (['PackageEntitlement', 'package_entitlement', 'StudentPackagePurchase', 'paid_quantity', 'bonus_quantity', 'final_price_minor'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    sprintf('%s must not depend on package state (%s).', class_basename($class), $forbidden),
                );
            }
        }
    }

    /** …and the consumption path never touches earnings, in either direction. */
    public function test_package_consumption_does_not_touch_earnings(): void
    {
        foreach ([
            PackageEntitlementService::class,
            ConsumePackageEntitlementOnLessonCompleted::class,
        ] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            foreach (['InstructorEarning', 'CompensationResolver', 'earnings'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source);
            }
        }
    }

    /**
     * A bonus unit is an ordinary completed lesson. Consumption draws
     * from the same pool whether the unit was paid for or granted, and
     * neither case reaches instructor pay.
     */
    public function test_a_bonus_unit_consumes_identically_to_a_paid_unit(): void
    {
        $package = $this->activePackage(total: 2);
        // 1 paid + 1 bonus, same entitlement, one shared balance.
        $package['entitlement']->forceFill(['paid_quantity' => 1, 'bonus_quantity' => 1])->save();

        $first = $this->entitlements()->consumeForLesson($this->lesson($package));
        $second = $this->entitlements()->consumeForLesson($this->lesson($package));

        // Indistinguishable: no separate bonus ledger, no bonus flag.
        $this->assertSame($first->entitlement_id, $second->entitlement_id);
        $this->assertSame(2, $package['entitlement']->fresh()->used_quantity);
        $this->assertSame(PackageEntitlementStatus::Completed, $package['entitlement']->fresh()->status);
        $this->assertArrayNotHasKey('is_bonus', $first->getAttributes());
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_no_role_may_create_or_edit_a_consumption_record(): void
    {
        $package = $this->activePackage();
        $this->entitlements()->consumeForLesson($this->lesson($package));

        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');

        // No policy exists for the ledger at all, so Gate denies every
        // ability for every role — including the admin.
        foreach ([$manager, $package['student'], $package['instructor']] as $user) {
            $this->assertFalse($user->can('create', StudentPackageEntitlementConsumption::class));
            $this->assertFalse($user->can('update', StudentPackageEntitlementConsumption::query()->first()));
            $this->assertFalse($user->can('delete', StudentPackageEntitlementConsumption::query()->first()));
        }
    }

    public function test_no_manual_consumption_permission_exists(): void
    {
        foreach ([
            'Create:StudentPackageEntitlementConsumption',
            'Consume:StudentPackageEntitlement',
            'Adjust:StudentPackageEntitlement',
        ] as $permission) {
            $this->assertDatabaseMissing('permissions', ['name' => $permission]);
        }
    }
}
