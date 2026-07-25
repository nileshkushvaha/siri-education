<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\InstructorSubjectTopic;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class StudentBookingTopicSelectionTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private SubjectTopic $algebra;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        // The priced paid type + subject master ('maths') the booking flow uses.
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);

        $this->algebra = SubjectTopic::factory()->create([
            'subject_id' => Subject::query()->where('slug', 'maths')->firstOrFail()->id,
            'name' => 'Algebra',
            'slug' => 'algebra',
        ]);
    }

    private function book(?string $topic, int $hour = 10): Booking
    {
        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime($hour, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
            topic: $topic,
        ))->refresh();
    }

    private function grantCoverage(): InstructorSubjectTopic
    {
        return InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $this->algebra->id,
        ]);
    }

    public function test_booking_with_topic_succeeds_when_teacher_has_coverage_and_stores_snapshot(): void
    {
        $this->grantCoverage();

        $booking = $this->book('algebra');

        $this->assertSame('algebra', $booking->meta['topic']);
        $this->assertSame($this->algebra->id, $booking->meta['topic_id']);
        $this->assertSame('maths', $booking->meta['subject']);
    }

    public function test_booking_rejects_teacher_without_topic_coverage(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('does not teach the selected topic');

        $this->book('algebra');
    }

    public function test_booking_rejects_inactive_topic(): void
    {
        $this->grantCoverage();
        $this->algebra->update(['status' => 'inactive']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('topic is not available');

        $this->book('algebra');
    }

    public function test_booking_without_topic_still_works_with_subject_coverage_only(): void
    {
        // No InstructorSubjectTopic rows at all — pre-Phase-12.5 behavior.
        $booking = $this->book(null);

        $this->assertArrayNotHasKey('topic', $booking->meta);
        $this->assertSame('maths', $booking->meta['subject']);
    }

    public function test_topic_booking_still_prices_through_the_matrix(): void
    {
        $this->grantCoverage();

        $booking = $this->book('algebra');

        // Topic affects matching only — the price snapshot comes from
        // student_lesson_prices by subject/level/country/duration.
        $this->assertSame('499.00', $booking->price);
        $this->assertSame('INR', $booking->currency);
        $this->assertSame('pending', $booking->payment_status->value);
    }

    public function test_topic_selection_for_a_level_scoped_coverage_respects_grade(): void
    {
        $level = AcademicLevel::create([
            'name' => 'High School',
            'slug' => 'high-school',
            'min_grade' => 9,
            'max_grade' => 12,
        ]);
        InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $this->algebra->id,
            'academic_level_id' => $level->id,
        ]);

        // Grade 7 booking against 9-12 coverage → rejected.
        $this->expectException(BookingException::class);
        $this->book('algebra');
    }
}
