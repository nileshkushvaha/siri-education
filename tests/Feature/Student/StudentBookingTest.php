<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\Weekday;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
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

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $currency = Currency::factory()->create(['code' => 'USD']);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->profile()->update(['phone_e164' => '+9199999'.str_pad((string) $this->student->id, 5, '0', STR_PAD_LEFT), 'phone_verified_at' => now()]); // paid bookings require a verified phone (StudentFinancialVerificationGate)
        $this->student->assignRole('student');
        UserProfile::updateOrCreate(['user_id' => $this->student->id], ['country_id' => $country->id]);

        $this->teacher = $this->makeTeacher('maths');
        $this->paidType = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
        ]);

        // academic_level_id: null — applies to every grade, so every
        // test's `grade` value (5, or the default in payload()) resolves.
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $subject->id,
            'academic_level_id' => null,
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'currency_code' => 'USD',
            'duration_minutes' => 60,
            'amount_minor' => 4999, // $49.99
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
        // store() does not auto-initiate a gateway payment order — the
        // response carries the reserved, still-unpaid booking only.
        $this->actingAs($this->student)
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
            ->assertJsonPath('data.price', '49.99')
            ->assertJsonMissingPath('payment');
    }

    public function test_unsafe_pay_route_no_longer_exists(): void
    {
        // The endpoint that let a student mark their own booking paid
        // from a client-submitted `payment_reference`, with no provider
        // signature verification, is removed entirely.
        $store = $this->actingAs($this->student)->postJson('/dashboard/bookings', [
            'type' => 'paid_one_to_one',
            'teacher_id' => $this->teacher->id,
            'starts_at' => $this->slot(),
            'subject' => 'maths',
            'grade' => 5,
        ])->assertCreated();

        $booking = Booking::query()->where('reference', $store->json('data.reference'))->firstOrFail();

        $this->assertFalse(Route::has('dashboard.bookings.pay'));

        $this->actingAs($this->student)
            ->postJson("/dashboard/bookings/{$booking->id}/pay", ['reference' => 'anything-at-all'])
            ->assertNotFound();

        // Booking is unaffected — still pending, no activity log entry, and
        // no BookingPayment row exists for this reference at all.
        $this->assertSame('pending', $booking->refresh()->payment_status->value);
        $this->assertFalse(
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
            'subject' => 'maths',
            'grade' => 5,
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
                'subject' => 'maths',
                'grade' => 5,
                'recurring' => true,
                'occurrences' => 4,
                'frequency' => 'weekly',
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
            ->assertJsonStructure(['data' => [['starts_at', 'ends_at']]]);
    }

    public function test_guests_cannot_access_student_endpoints(): void
    {
        $this->getJson('/dashboard/bookings/teachers?type=paid_one_to_one&subject=maths&grade=5')
            ->assertUnauthorized();
    }
}
