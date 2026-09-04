<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\BookingStatus;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Services\RecordingEligibilityResolver;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\Booking;
use App\Models\Country;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every AND-term in the eligibility chain, tested independently so a
 * future regression can never silently widen
 * the gate. Recording stays OFF unless every single one passes.
 */
final class RecordingEligibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private RecordingEligibilityResolver $resolver;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->resolver = app(RecordingEligibilityResolver::class);

        $this->student = $this->makeStudent();
        $this->instructor = $this->makeInstructor();

        FakeMeetingProvider::reset();
    }

    private function makeStudent(bool $consents = true, string $status = User::STATUS_ACTIVE): User
    {
        $student = User::factory()->create(['status' => $status]);
        $student->assignRole('student');
        $student->profile->update(['student_status' => StudentStatus::Active, 'consents_to_recording' => $consents]);

        return $student;
    }

    private function makeInstructor(bool $consents = true, string $status = User::STATUS_ACTIVE): User
    {
        $instructor = User::factory()->create(['status' => $status]);
        $instructor->assignRole('instructor');
        $instructor->profile->update(['instructor_status' => InstructorStatus::Active, 'consents_to_recording' => $consents]);

        return $instructor;
    }

    private function enableAllGlobalFlags(): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();

        $meetings = app(MeetingSettings::class);
        $meetings->recording_enabled = true;
        $meetings->save();
    }

    private function confirmedBooking(?User $student = null, ?User $instructor = null): Booking
    {
        return Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'student_id' => ($student ?? $this->student)->id,
            'instructor_id' => ($instructor ?? $this->instructor)->id,
        ]);
    }

    public function test_fully_eligible_when_every_gate_passes(): void
    {
        $this->enableAllGlobalFlags();
        $booking = $this->confirmedBooking();

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertTrue($result->eligible);
        $this->assertNull($result->reason);
    }

    public function test_ineligible_when_global_platform_flag_is_off(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_enabled = true;
        $meetings->save();
        // features.recording_enabled stays at its default false.

        $booking = $this->confirmedBooking();

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('recording_not_available', $result->reason);
    }

    public function test_ineligible_when_meeting_level_flag_is_off(): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();
        // meeting.recording_enabled stays at its default false.

        $booking = $this->confirmedBooking();

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('recording_not_available', $result->reason);
    }

    public function test_ineligible_when_country_disables_recording_availability(): void
    {
        $this->enableAllGlobalFlags();
        $country = Country::factory()->create(['feature_flags' => ['recording_availability' => false]]);
        $this->student->profile->update(['country_id' => $country->id]);

        $booking = $this->confirmedBooking();

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('recording_not_available', $result->reason);
    }

    public function test_ineligible_when_the_active_provider_lacks_recording_capability(): void
    {
        $this->enableAllGlobalFlags();
        $booking = $this->confirmedBooking();

        $result = $this->resolver->evaluate($booking, new ManualMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('provider_capability_missing', $result->reason);
    }

    public function test_ineligible_when_provider_declares_no_recording_support_via_flag(): void
    {
        $this->enableAllGlobalFlags();
        FakeMeetingProvider::$supportsRecording = false;
        $booking = $this->confirmedBooking();

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('provider_capability_missing', $result->reason);
    }

    public function test_ineligible_when_booking_is_not_confirmed(): void
    {
        $this->enableAllGlobalFlags();
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('booking_not_confirmed', $result->reason);
    }

    /** A completed booking is a confirmed booking that ran — the sweep may register it late. */
    public function test_a_completed_booking_is_eligible_but_a_cancelled_one_is_not(): void
    {
        $this->enableAllGlobalFlags();

        $completed = Booking::factory()->create(['status' => BookingStatus::Completed, 'student_id' => $this->student->id, 'instructor_id' => $this->instructor->id]);
        $this->assertTrue($this->resolver->evaluate($completed, new FakeMeetingProvider)->eligible);

        $cancelled = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'student_id' => $this->student->id, 'instructor_id' => $this->instructor->id]);
        $this->assertSame('booking_not_confirmed', $this->resolver->evaluate($cancelled, new FakeMeetingProvider)->reason);
    }

    public function test_ineligible_when_the_student_is_not_an_active_account(): void
    {
        $this->enableAllGlobalFlags();
        $inactiveStudent = $this->makeStudent(status: User::STATUS_INACTIVE);
        $booking = $this->confirmedBooking(student: $inactiveStudent);

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('participant_lifecycle_restricted', $result->reason);
    }

    public function test_ineligible_when_the_instructor_is_not_an_active_account(): void
    {
        $this->enableAllGlobalFlags();
        $inactiveInstructor = $this->makeInstructor(status: User::STATUS_INACTIVE);
        $booking = $this->confirmedBooking(instructor: $inactiveInstructor);

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('participant_lifecycle_restricted', $result->reason);
    }

    public function test_ineligible_when_the_student_has_not_consented(): void
    {
        $this->enableAllGlobalFlags();
        $nonConsentingStudent = $this->makeStudent(consents: false);
        $booking = $this->confirmedBooking(student: $nonConsentingStudent);

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('student_consent_missing', $result->reason);
    }

    public function test_ineligible_when_the_instructor_has_not_consented(): void
    {
        $this->enableAllGlobalFlags();
        $nonConsentingInstructor = $this->makeInstructor(consents: false);
        $booking = $this->confirmedBooking(instructor: $nonConsentingInstructor);

        $result = $this->resolver->evaluate($booking, new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('instructor_consent_missing', $result->reason);
    }

    public function test_ineligible_when_a_previously_consenting_student_withdraws_consent(): void
    {
        $this->enableAllGlobalFlags();
        $booking = $this->confirmedBooking();
        $this->assertTrue($this->resolver->evaluate($booking, new FakeMeetingProvider)->eligible);

        $this->student->profile->update(['consents_to_recording' => false]);

        $result = $this->resolver->evaluate($booking->fresh(), new FakeMeetingProvider);

        $this->assertFalse($result->eligible);
        $this->assertSame('student_consent_missing', $result->reason);
    }
}
