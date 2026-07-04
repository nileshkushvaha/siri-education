<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private BookingType $paidType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher = $this->makeTeacher('maths');
        $this->paidType = BookingType::factory()->paid(49.99, 'USD')->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
            'max_attendees' => 1,
        ]);
    }

    private function makeTeacher(string $subject): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject($subject, 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        return $teacher;
    }

    private function slot(int $daysAhead = 3, int $hour = 10): string
    {
        return now('UTC')->addDays($daysAhead)->setTime($hour, 0)->toIso8601String();
    }

    public function test_student_can_browse_eligible_teachers(): void
    {
        $englishTeacher = $this->makeTeacher('english');

        $response = $this->actingAs($this->student)
            ->getJson('/dashboard/bookings/teachers?type=paid_one_to_one&subject=maths&grade=5')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->teacher->id));
        $this->assertFalse($ids->contains($englishTeacher->id));
    }

    public function test_student_books_paid_session_with_chosen_teacher(): void
    {
        $response = $this->actingAs($this->student)
            ->postJson('/dashboard/bookings', [
                'type' => 'paid_one_to_one',
                'teacher_id' => $this->teacher->id,
                'starts_at' => $this->slot(),
                'subject' => 'maths',
                'grade' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.price', '49.99');

        $this->assertNotNull($response->json('payment.reference'));
        $this->assertSame('pending', $response->json('payment.status'));
    }

    public function test_payment_placeholder_marks_booking_paid(): void
    {
        $store = $this->actingAs($this->student)->postJson('/dashboard/bookings', [
            'type' => 'paid_one_to_one',
            'teacher_id' => $this->teacher->id,
            'starts_at' => $this->slot(),
        ])->assertCreated();

        $booking = Booking::query()->where('reference', $store->json('data.reference'))->firstOrFail();
        $reference = $store->json('payment.reference');

        // Wrong reference rejected
        $this->actingAs($this->student)
            ->postJson("/dashboard/bookings/{$booking->id}/pay", ['reference' => 'WRONG'])
            ->assertStatus(422);

        // Someone else's booking is forbidden
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actingAs($other)
            ->postJson("/dashboard/bookings/{$booking->id}/pay", ['reference' => $reference])
            ->assertForbidden();

        // Owner pays
        $this->actingAs($this->student)
            ->postJson("/dashboard/bookings/{$booking->id}/pay", ['reference' => $reference])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertTrue(
            BookingActivity::query()
                ->where('booking_id', $booking->id)
                ->where('action', BookingActivityAction::PaymentStatusChanged)
                ->exists(),
        );
    }

    public function test_previous_teachers_lists_booked_hosts(): void
    {
        $this->actingAs($this->student)->postJson('/dashboard/bookings', [
            'type' => 'paid_one_to_one',
            'teacher_id' => $this->teacher->id,
            'starts_at' => $this->slot(),
        ])->assertCreated();

        $this->actingAs($this->student)
            ->getJson('/dashboard/bookings/previous-teachers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->teacher->id);
    }

    public function test_recurring_booking_skips_conflicts_and_reports(): void
    {
        // Teacher on leave during the third weekly occurrence
        $thirdWeek = now('UTC')->addDays(3)->addWeeks(2);
        TeacherUnavailability::factory()->state([
            'teacher_id' => $this->teacher->id,
            'starts_at' => $thirdWeek->copy()->startOfDay(),
            'ends_at' => $thirdWeek->copy()->endOfDay(),
        ])->create();

        $response = $this->actingAs($this->student)
            ->postJson('/dashboard/bookings', [
                'type' => 'paid_one_to_one',
                'teacher_id' => $this->teacher->id,
                'starts_at' => $this->slot(),
                'recurring' => true,
                'occurrences' => 4,
            ])
            ->assertCreated();

        $this->assertCount(3, $response->json('data'));
        $this->assertCount(1, $response->json('failures'));
        $this->assertNotNull($response->json('recurring_group'));
        $this->assertSame($response->json('recurring_group'), $response->json('data.0.recurring_group'));
    }

    public function test_teacher_must_teach_requested_subject(): void
    {
        $this->actingAs($this->student)
            ->postJson('/dashboard/bookings', [
                'type' => 'paid_one_to_one',
                'teacher_id' => $this->teacher->id,
                'starts_at' => $this->slot(),
                'subject' => 'science',
                'grade' => 5,
            ])
            ->assertStatus(422);
    }

    public function test_slots_endpoint_returns_teacher_slots(): void
    {
        $this->actingAs($this->student)
            ->getJson('/dashboard/bookings/slots?type=paid_one_to_one&teacher_id='.$this->teacher->id.'&date='.now()->addDays(3)->toDateString())
            ->assertOk()
            ->assertJsonStructure(['data' => [['starts_at', 'ends_at', 'remaining_capacity']]]);
    }

    public function test_guests_cannot_access_student_endpoints(): void
    {
        $this->getJson('/dashboard/bookings/teachers?type=paid_one_to_one&subject=maths&grade=5')
            ->assertUnauthorized();
    }
}
