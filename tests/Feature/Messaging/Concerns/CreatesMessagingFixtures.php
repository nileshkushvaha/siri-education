<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Concerns;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

trait CreatesMessagingFixtures
{
    protected function ensureMessagingRoles(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    }

    protected function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        return $student;
    }

    protected function instructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    protected function manager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    protected function confirmedPaidBooking(User $student, User $instructor): Booking
    {
        $type = BookingType::factory()->create(['key' => 'paid_'.uniqid(), 'is_paid' => true]);
        $startsAt = CarbonImmutable::now('UTC')->addDays(2);

        return Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);
    }

    /** SRS §17.28 "Demo-only messaging may be restricted or limited." */
    protected function unpaidDemoBooking(User $student, User $instructor): Booking
    {
        $type = BookingType::factory()->create(['key' => 'free_demo_'.uniqid(), 'is_paid' => false]);
        $startsAt = CarbonImmutable::now('UTC')->addDays(2);

        return Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);
    }

    protected function activeLearningPlan(User $student, User $instructor): StudentLearningPlan
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(['slug' => 'maths'], ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active']);

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        return StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'primary_instructor_user_id' => $instructor->id,
            'learning_goal_id' => $goal->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
            'started_at' => now()->subDays(5),
        ]);
    }

    protected function upcomingLesson(User $student, User $instructor): Lesson
    {
        $booking = $this->confirmedPaidBooking($student, $instructor);
        $startsAt = CarbonImmutable::now('UTC')->addDays(3);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
        ]);
    }

    /** SRS §17.28 "Demo-only messaging may be restricted or limited." */
    protected function upcomingDemoLesson(User $student, User $instructor): Lesson
    {
        $booking = $this->unpaidDemoBooking($student, $instructor);
        $startsAt = CarbonImmutable::now('UTC')->addDays(3);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);
    }

    protected function recentlyCompletedLesson(User $student, User $instructor, int $daysAgo = 2): Lesson
    {
        $booking = $this->confirmedPaidBooking($student, $instructor);
        // A completed lesson's booking has already transitioned out of
        // Confirmed in real usage — without this, findEligibleContext()'s
        // first (confirmed-paid-booking) check would trivially match
        // regardless of the lesson window being tested.
        $booking->update(['status' => BookingStatus::Completed]);
        $endsAt = CarbonImmutable::now('UTC')->subDays($daysAgo);

        return Lesson::factory()->completed()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $endsAt->subMinutes(60),
            'ends_at' => $endsAt,
        ]);
    }
}
