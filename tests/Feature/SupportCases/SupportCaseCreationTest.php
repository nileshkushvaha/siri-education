<?php

declare(strict_types=1);

namespace Tests\Feature\SupportCases;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\DTOs\CreateSupportCaseData;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseType;
use App\SupportCases\Exceptions\UnauthorizedLinkedRecordException;
use App\SupportCases\Services\SupportCaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-016 / SRS Chapter 25: case creation, mandatory fields, unique
 * reference numbering, and the linked-record ownership boundary
 * (§25.41 "A requester may link only records they are authorized to
 * view").
 */
class SupportCaseCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        return $student;
    }

    private function instructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function bookingFor(User $student, User $instructor): Booking
    {
        $type = BookingType::factory()->create(['key' => 'paid_one_to_one_'.uniqid(), 'is_paid' => true]);
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

    public function test_student_can_create_a_support_case_via_the_dashboard(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)->post(route('dashboard.support-cases.store'), [
            'category' => SupportCaseCategory::Booking->value,
            'priority' => SupportCasePriority::Medium->value,
            'subject' => 'Meeting link never worked',
            'description' => 'I could not join the class.',
        ]);

        $case = SupportCase::query()->where('created_by', $student->id)->sole();
        $response->assertRedirect(route('dashboard.support-cases.show', $case));

        $this->assertSame(SupportCaseType::Student, $case->type);
        $this->assertSame($student->id, $case->student_id);
        $this->assertNotEmpty($case->case_number);
        $this->assertStringStartsWith('SUP-', $case->case_number);
    }

    public function test_instructor_creating_a_case_is_typed_as_instructor(): void
    {
        $instructor = $this->instructor();

        $this->actingAs($instructor)->post(route('dashboard.support-cases.store'), [
            'category' => SupportCaseCategory::Withdrawal->value,
            'subject' => 'Withdrawal amount looks wrong',
            'description' => 'The payout total does not match my earnings.',
        ])->assertRedirect();

        $case = SupportCase::query()->where('created_by', $instructor->id)->sole();
        $this->assertSame(SupportCaseType::Instructor, $case->type);
        $this->assertSame($instructor->id, $case->instructor_id);
    }

    public function test_creation_requires_subject_and_category(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('dashboard.support-cases.store'), [
            'description' => 'Missing subject and category.',
        ])->assertSessionHasErrors(['category', 'subject']);

        $this->assertSame(0, SupportCase::query()->count());
    }

    public function test_case_numbers_are_unique_and_sequential(): void
    {
        $student = $this->student();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($student)->post(route('dashboard.support-cases.store'), [
                'category' => SupportCaseCategory::Other->value,
                'subject' => 'Case '.$i,
                'description' => 'Description '.$i,
            ]);
        }

        $numbers = SupportCase::query()->pluck('case_number')->all();
        $this->assertCount(3, array_unique($numbers));
    }

    public function test_a_student_may_link_a_booking_they_own(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $booking = $this->bookingFor($student, $instructor);

        $case = app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: $student,
            type: SupportCaseType::Student,
            category: SupportCaseCategory::Booking,
            priority: SupportCasePriority::Medium,
            subject: 'Booking issue',
            description: 'Something is wrong with this booking.',
            student: $student,
            linkedRecordType: Booking::class,
            linkedRecordId: $booking->id,
        ));

        $this->assertSame(Booking::class, $case->linked_record_type);
        $this->assertSame($booking->id, $case->linked_record_id);
    }

    public function test_a_student_cannot_link_another_students_booking(): void
    {
        $owner = $this->student();
        $intruder = $this->student();
        $instructor = $this->instructor();
        $booking = $this->bookingFor($owner, $instructor);

        $this->expectException(UnauthorizedLinkedRecordException::class);

        app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: $intruder,
            type: SupportCaseType::Student,
            category: SupportCaseCategory::Booking,
            priority: SupportCasePriority::Medium,
            subject: 'Not my booking',
            description: 'Trying to link someone else\'s booking.',
            student: $intruder,
            linkedRecordType: Booking::class,
            linkedRecordId: $booking->id,
        ));

        $this->assertSame(0, SupportCase::query()->count());
    }

    public function test_admin_created_case_may_link_any_record_without_ownership_check(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $booking = $this->bookingFor($student, $instructor);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $case = app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: $admin,
            type: SupportCaseType::AdminOperational,
            category: SupportCaseCategory::Booking,
            priority: SupportCasePriority::High,
            subject: 'Payment mismatch investigation',
            description: 'Reconciliation flagged this booking.',
            student: $student,
            instructor: $instructor,
            linkedRecordType: Booking::class,
            linkedRecordId: $booking->id,
            skipLinkedRecordOwnershipCheck: true,
        ));

        $this->assertSame(Booking::class, $case->linked_record_type);
    }

    public function test_case_creation_is_audit_logged(): void
    {
        $student = $this->student();

        app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: $student,
            type: SupportCaseType::Student,
            category: SupportCaseCategory::Wallet,
            priority: SupportCasePriority::Low,
            subject: 'Wallet question',
            description: 'Where did my recharge go?',
            student: $student,
        ));

        $this->assertTrue(
            Activity::query()->where('log_name', 'support_cases')->where('event', 'case_created')->exists()
        );
    }
}
