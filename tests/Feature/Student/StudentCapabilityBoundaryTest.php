<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningGoalType;
use App\Enums\StudentStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Services\HomeworkService;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\HomeworkAssignment;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\Subject;
use App\Models\User;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\StudentReviewStatus;
use App\Services\Student\LearningPlanService;
use App\Services\Student\StudentFavoriteInstructorService;
use App\Services\Student\StudentLearningGoalService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\MeetingSettings;
use App\Settings\ReviewSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24H.2 — GAP-013: every interactive student capability requires
 * `student role AND student_status === Active`, enforced centrally by
 * StudentLifecycleService::assertEligibleForStudentAction() at the
 * service boundary of each domain (favorites, review submission and
 * reporting, learning goals, learning-plan drafts, homework submission,
 * referral participation). Non-Active statuses — and null/missing
 * profiles — fail closed with one generic message. Restriction affects
 * AUTHORIZATION only: existing records are never deleted or mutated,
 * and system/financial side effects (wallet credits) stay
 * status-independent.
 */
class StudentCapabilityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    private function subject(): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'mathematics'], ['name' => 'Mathematics']);

        return Subject::query()->firstOrCreate(['slug' => 'algebra'], ['academic_category_id' => $category->id, 'name' => 'Algebra']);
    }

    /** @return array<string, array{0: StudentStatus|null}> */
    public static function nonActiveStatuses(): array
    {
        return [
            'registered' => [StudentStatus::Registered],
            'suspended' => [StudentStatus::Suspended],
            'archived' => [StudentStatus::Archived],
            'null status' => [null],
        ];
    }

    private function studentWith(?StudentStatus $status): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        if ($status !== null) {
            $student->profile()->update(['student_status' => $status]);
        }

        return $student;
    }

    private function activeStudent(): User
    {
        return $this->studentWith(StudentStatus::Active);
    }

    private function bookableInstructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update([
            'instructor_status' => InstructorStatus::Active,
            'profile_visibility' => 'public',
        ]);

        return $instructor;
    }

    private function lifecycle(): StudentLifecycleService
    {
        return app(StudentLifecycleService::class);
    }

    // ── 1-4. Central guard ────────────────────────────────────────────────────

    public function test_active_student_passes_the_central_guard(): void
    {
        $this->lifecycle()->assertEligibleForStudentAction($this->activeStudent());

        $this->addToAssertionCount(1);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_statuses_fail_the_central_guard(?StudentStatus $status): void
    {
        $this->expectException(StudentActionNotAvailableException::class);

        $this->lifecycle()->assertEligibleForStudentAction($this->studentWith($status));
    }

    public function test_missing_profile_fails_the_central_guard(): void
    {
        $student = $this->activeStudent();
        $student->profile()->delete();

        $this->expectException(StudentActionNotAvailableException::class);

        $this->lifecycle()->assertEligibleForStudentAction($student->fresh());
    }

    public function test_user_without_student_role_fails_even_with_active_profile_status(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->profile()->update(['student_status' => StudentStatus::Active]);

        $this->expectException(StudentActionNotAvailableException::class);

        $this->lifecycle()->assertEligibleForStudentAction($user);
    }

    /** The rejection message is one fixed generic sentence — identical for every status, never naming it. */
    public function test_guard_error_does_not_reveal_the_exact_status(): void
    {
        $messages = [];

        foreach ([StudentStatus::Registered, StudentStatus::Suspended, StudentStatus::Archived, null] as $status) {
            try {
                $this->lifecycle()->assertEligibleForStudentAction($this->studentWith($status));
                $this->fail('Expected rejection.');
            } catch (StudentActionNotAvailableException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(1, array_unique($messages), 'All statuses must produce the identical generic message.');
        $this->assertStringNotContainsString('suspend', strtolower($messages[0]));
        $this->assertStringNotContainsString('archiv', strtolower($messages[0]));
        $this->assertStringNotContainsString('register', strtolower($messages[0]));
    }

    // ── 5-7. Favorites ────────────────────────────────────────────────────────

    public function test_active_student_can_favorite_and_unfavorite(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->bookableInstructor();
        $service = app(StudentFavoriteInstructorService::class);

        $service->favorite($student, $instructor);
        $this->assertDatabaseHas('student_favorite_instructors', ['student_user_id' => $student->id, 'instructor_user_id' => $instructor->id]);

        $service->unfavorite($student, $instructor);
        $this->assertDatabaseMissing('student_favorite_instructors', ['student_user_id' => $student->id, 'instructor_user_id' => $instructor->id]);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_cannot_favorite(?StudentStatus $status): void
    {
        $this->expectException(StudentActionNotAvailableException::class);

        app(StudentFavoriteInstructorService::class)->favorite($this->studentWith($status), $this->bookableInstructor());
    }

    public function test_existing_favorites_remain_after_suspension(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->bookableInstructor();
        app(StudentFavoriteInstructorService::class)->favorite($student, $instructor);

        $this->suspend($student);

        $this->assertDatabaseHas('student_favorite_instructors', ['student_user_id' => $student->id, 'instructor_user_id' => $instructor->id]);
    }

    // ── 8-12. Reviews ─────────────────────────────────────────────────────────

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_cannot_submit_a_review(?StudentStatus $status): void
    {
        $student = $this->studentWith($status);
        $eligibility = LessonReviewEligibility::factory()->create(['student_id' => $student->id]);

        $this->expectException(StudentActionNotAvailableException::class);

        app(StudentReviewServiceInterface::class)->submit($eligibility, $student, new SubmitStudentReviewData(overallRating: 5, content: null));
    }

    public function test_non_active_student_cannot_report_a_review(): void
    {
        $this->enableReviewReporting();
        Permission::firstOrCreate(['name' => 'Report:LessonReview', 'guard_name' => 'web']);
        $student = $this->studentWith(StudentStatus::Suspended);
        $student->givePermissionTo('Report:LessonReview');
        $review = LessonReview::factory()->create();

        $this->expectException(StudentActionNotAvailableException::class);

        app(ReviewReportServiceInterface::class)->submit($review, $student, new SubmitReviewReportData(
            reason: ReviewReportReason::AbusiveLanguage,
            explanation: 'Contains abusive language toward the student.',
        ));
    }

    /** A reporter WITHOUT the student role (e.g. an instructor) is governed by the report permission alone — the lifecycle guard never applies. */
    public function test_instructor_reporter_is_not_blocked_by_the_student_lifecycle_guard(): void
    {
        $this->enableReviewReporting();
        Permission::firstOrCreate(['name' => 'Report:LessonReview', 'guard_name' => 'web']);
        $instructor = $this->bookableInstructor();
        $instructor->givePermissionTo('Report:LessonReview');
        $review = LessonReview::factory()->create(['status' => StudentReviewStatus::Published]);

        $report = app(ReviewReportServiceInterface::class)->submit($review, $instructor, new SubmitReviewReportData(
            reason: ReviewReportReason::AbusiveLanguage,
            explanation: 'Contains abusive language toward the instructor.',
        ));

        $this->assertDatabaseHas('review_reports', ['id' => $report->id]);
    }

    public function test_existing_review_rows_remain_unchanged_after_author_suspension(): void
    {
        $student = $this->activeStudent();
        $review = LessonReview::factory()->create(['student_id' => $student->id]);
        $original = $review->fresh()->getAttributes();

        $this->suspend($student);

        $this->assertSame($original, $review->fresh()->getAttributes());
    }

    // ── 13-16. Learning goals and plans ──────────────────────────────────────

    public function test_active_student_can_create_and_update_a_goal(): void
    {
        $student = $this->activeStudent();
        $subject = $this->subject();
        $service = app(StudentLearningGoalService::class);

        $goal = $service->create($student, $this->goalPayload($subject));
        $this->assertDatabaseHas('student_learning_goals', ['id' => $goal->id, 'user_id' => $student->id]);

        $service->update($student, $goal, [...$this->goalPayload($subject), 'title' => 'Updated goal title']);
        $this->assertSame('Updated goal title', $goal->fresh()->title);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_cannot_create_a_goal(?StudentStatus $status): void
    {
        $this->expectException(StudentActionNotAvailableException::class);

        app(StudentLearningGoalService::class)->create($this->studentWith($status), $this->goalPayload($this->subject()));
    }

    public function test_goals_remain_preserved_and_admin_can_still_update_after_suspension(): void
    {
        $student = $this->activeStudent();
        $subject = $this->subject();
        $service = app(StudentLearningGoalService::class);
        $goal = $service->create($student, $this->goalPayload($subject));

        $this->suspend($student);

        $this->assertDatabaseHas('student_learning_goals', ['id' => $goal->id, 'status' => LearningGoalStatus::Active->value]);

        // An admin acting through the policy permission is untouched by
        // the student's restricted status.
        Permission::firstOrCreate(['name' => 'Update:StudentLearningGoal', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo('Update:StudentLearningGoal');

        $updated = $service->update($admin, $goal->fresh(), [...$this->goalPayload($subject), 'title' => 'Admin-corrected title']);
        $this->assertSame('Admin-corrected title', $updated->title);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_cannot_create_a_learning_plan_draft(?StudentStatus $status): void
    {
        $activeOwner = $this->activeStudent();
        $subject = $this->subject();
        $goal = app(StudentLearningGoalService::class)->create($activeOwner, $this->goalPayload($subject));

        // The goal's owner subsequently loses Active status.
        $activeOwner->profile()->update(['student_status' => $status]);

        $this->expectException(StudentActionNotAvailableException::class);

        app(LearningPlanService::class)->createDraftFromGoal($activeOwner->fresh(), $goal->fresh());
    }

    // ── 17-20. Homework ──────────────────────────────────────────────────────

    public function test_active_student_homework_submission_succeeds(): void
    {
        $student = $this->activeStudent();
        $assignment = HomeworkAssignment::factory()->create(['student_id' => $student->id, 'status' => HomeworkStatus::Pending]);

        $submitted = app(HomeworkService::class)->submit($assignment, 'Here is my completed answer.');

        $this->assertSame(HomeworkStatus::Submitted, $submitted->status);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_cannot_submit_homework(?StudentStatus $status): void
    {
        $student = $this->studentWith($status);
        $assignment = HomeworkAssignment::factory()->create(['student_id' => $student->id, 'status' => HomeworkStatus::Pending]);

        $this->expectException(StudentActionNotAvailableException::class);

        app(HomeworkService::class)->submit($assignment, 'Attempted submission.');
    }

    public function test_homework_remains_preserved_and_instructor_review_available_after_suspension(): void
    {
        $student = $this->activeStudent();
        $assignment = HomeworkAssignment::factory()->create(['student_id' => $student->id, 'status' => HomeworkStatus::Pending]);
        app(HomeworkService::class)->submit($assignment, 'Submitted before suspension.');

        $this->suspend($student);

        $this->assertDatabaseHas('homework_assignments', ['id' => $assignment->id, 'status' => HomeworkStatus::Submitted->value]);

        // The instructor's review path takes no student-lifecycle guard.
        $reviewed = app(HomeworkService::class)->review($assignment->fresh(), 'Good work.', 'A');
        $this->assertSame(HomeworkStatus::Graded, $reviewed->status);
    }

    // ── 21-23/25. Meeting-link reveal ────────────────────────────────────────

    public function test_active_student_sees_the_meeting_join_url(): void
    {
        Http::fake();
        [$student, $booking] = $this->confirmedBookingWithMeeting(StudentStatus::Active);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('https://meet.example.test/abc');

        Http::assertNothingSent();
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_response_contains_no_provider_url(?StudentStatus $status): void
    {
        [$student, $booking] = $this->confirmedBookingWithMeeting($status);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee('https://meet.example.test/abc');
    }

    /** @return array{0: User, 1: Booking} */
    private function confirmedBookingWithMeeting(?StudentStatus $status): array
    {
        $settings = app(MeetingSettings::class);
        $settings->student_join_url_visible = true;
        $settings->save();

        $student = $this->studentWith($status);
        $instructor = $this->bookableInstructor();
        $type = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        $booking = Booking::factory()->for($type, 'type')->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
        ]);

        BookingMeeting::factory()->created()->create(['booking_id' => $booking->id]);

        return [$student, $booking];
    }

    // ── 26-31. Referral and wallet ───────────────────────────────────────────

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_cannot_create_a_referral_code(?StudentStatus $status): void
    {
        $this->expectException(StudentActionNotAvailableException::class);

        app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($this->studentWith($status));
    }

    public function test_active_student_can_create_a_referral_code(): void
    {
        $code = app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($this->activeStudent());

        $this->assertDatabaseHas('referral_codes', ['id' => $code->id]);
    }

    /** System/financial side effects stay status-independent: a wallet credit reaches a suspended and an archived student. */
    public function test_wallet_credit_still_reaches_a_suspended_and_an_archived_student(): void
    {
        foreach ([StudentStatus::Suspended, StudentStatus::Archived] as $status) {
            $student = $this->activeStudent();
            $wallet = app(WalletService::class)->getOrCreateWallet($student, null, $student);
            $student->profile()->update(['student_status' => $status]);

            $entry = app(WalletLedgerService::class)->credit(
                $wallet,
                5000,
                WalletLedgerEntryType::Refund,
                User::factory()->create(['status' => User::STATUS_ACTIVE]),
                description: 'Protective refund credit.',
            );

            $this->assertDatabaseHas('wallet_ledger_entries', ['id' => $entry->id]);
            $this->assertSame(5000, $wallet->fresh()->balance_minor);
        }
    }

    // ── 32/34. Stale Livewire + no partial side effects ──────────────────────

    /** A stale session's action after suspension is rejected authoritatively at the service layer, with no partial record. */
    public function test_stale_action_after_suspension_is_rejected_with_no_side_effects(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->bookableInstructor();

        // Component/page state was loaded while Active; the suspension
        // lands before the queued action executes.
        $this->suspend($student);

        try {
            app(StudentFavoriteInstructorService::class)->favorite($student->fresh(), $instructor);
            $this->fail('Expected rejection.');
        } catch (StudentActionNotAvailableException) {
            // expected
        }

        $this->assertDatabaseMissing('student_favorite_instructors', ['student_user_id' => $student->id]);
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'student_favorites', 'causer_id' => $student->id]);
    }

    // ── 35/36. Preservation + reactivation ───────────────────────────────────

    public function test_reactivated_student_regains_capabilities(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->bookableInstructor();

        $this->suspend($student);
        $this->reactivate($student);

        $favorite = app(StudentFavoriteInstructorService::class)->favorite($student->fresh(), $instructor);

        $this->assertDatabaseHas('student_favorite_instructors', ['id' => $favorite->id]);
    }

    public function test_archived_student_remains_blocked_from_all_guarded_capabilities(): void
    {
        $student = $this->activeStudent();
        $this->archive($student);

        $this->expectException(StudentActionNotAvailableException::class);

        app(StudentFavoriteInstructorService::class)->favorite($student->fresh(), $this->bookableInstructor());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function goalPayload(Subject $subject): array
    {
        return [
            'title' => 'Improve algebra fundamentals',
            'type' => LearningGoalType::SkillDevelopment->value,
            'subject_id' => $subject->id,
        ];
    }

    private function suspend(User $student): void
    {
        app(StudentLifecycleService::class)->suspend($student, $this->lifecycleAdmin(), 'Capability boundary test suspension.');
    }

    private function archive(User $student): void
    {
        app(StudentLifecycleService::class)->archive($student, $this->lifecycleAdmin(), 'Capability boundary test archive.');
    }

    private function reactivate(User $student): void
    {
        app(StudentLifecycleService::class)->reactivate($student, $this->lifecycleAdmin(), 'Capability boundary test reactivation.');
    }

    private function enableReviewReporting(): void
    {
        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->review_reporting_enabled = true;
        $settings->save();
    }

    private function lifecycleAdmin(): User
    {
        collect([
            StudentLifecycleService::SUSPEND_PERMISSION,
            StudentLifecycleService::REACTIVATE_PERMISSION,
            StudentLifecycleService::ARCHIVE_PERMISSION,
        ])->each(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo([
            StudentLifecycleService::SUSPEND_PERMISSION,
            StudentLifecycleService::REACTIVATE_PERMISSION,
            StudentLifecycleService::ARCHIVE_PERMISSION,
        ]);

        return $admin;
    }
}
