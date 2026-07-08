<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Filament\Resources\TeacherAvailability\Pages\ListTeacherAvailability;
use App\Filament\Resources\TeacherLeave\Pages\ListTeacherLeave;
use App\Livewire\Frontend\Instructor\AvailabilityManager;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\HomeworkAssignment;
use App\Models\LearningPlanReview;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Services\Instructor\InstructorTimeOffService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorAvailabilityHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── Invalid ranges ──────────────────────────────────────────────

    public function test_weekly_availability_rejects_start_time_after_or_equal_end_time(): void
    {
        $teacher = $this->instructor();

        $this->expectException(ValidationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '11:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $teacher);
    }

    public function test_time_off_rejects_starts_at_after_or_equal_ends_at(): void
    {
        $teacher = $this->instructor();

        $this->expectException(ValidationException::class);

        app(InstructorTimeOffService::class)->create([
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-08-01 10:00:00',
            'ends_at' => '2026-08-01 09:00:00',
            'timezone' => 'UTC',
        ], $teacher);
    }

    public function test_weekly_availability_rejects_invalid_timezone(): void
    {
        $teacher = $this->instructor();

        $this->expectException(ValidationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'timezone' => 'Not/AZone',
            'is_active' => true,
        ], $teacher);
    }

    public function test_time_off_rejects_invalid_timezone(): void
    {
        $teacher = $this->instructor();

        $this->expectException(ValidationException::class);

        app(InstructorTimeOffService::class)->create([
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-08-01 09:00:00',
            'ends_at' => '2026-08-01 10:00:00',
            'timezone' => 'Not/AZone',
        ], $teacher);
    }

    public function test_weekly_availability_rejects_effective_until_before_effective_from(): void
    {
        $teacher = $this->instructor();

        $this->expectException(ValidationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'effective_from' => '2026-08-01',
            'effective_until' => '2026-07-01',
            'is_active' => true,
        ], $teacher);
    }

    // ── Cross-user denial (service) ──────────────────────────────────

    public function test_instructor_cannot_create_availability_for_another_instructor_via_service(): void
    {
        $actor = $this->instructor();
        $otherTeacher = $this->instructor();

        $this->expectException(AuthorizationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $otherTeacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $actor);
    }

    public function test_instructor_cannot_update_another_instructors_weekly_availability_via_service(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $window = TeacherAvailability::factory()->create(['teacher_id' => $owner->id, 'timezone' => 'UTC']);

        $this->expectException(AuthorizationException::class);

        app(InstructorAvailabilityService::class)->update($window, ['start_time' => '10:00'], $intruder);
    }

    public function test_instructor_cannot_delete_another_instructors_weekly_availability_via_service(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $window = TeacherAvailability::factory()->create(['teacher_id' => $owner->id, 'timezone' => 'UTC']);

        try {
            app(InstructorAvailabilityService::class)->delete($window, $intruder);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('teacher_availability', ['id' => $window->id]);
    }

    public function test_instructor_cannot_update_another_instructors_time_off_via_service(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $leave = TeacherUnavailability::factory()->create(['teacher_id' => $owner->id, 'timezone' => 'UTC']);

        $this->expectException(AuthorizationException::class);

        app(InstructorTimeOffService::class)->update($leave, ['reason' => 'Changed by intruder'], $intruder);
    }

    public function test_instructor_cannot_delete_another_instructors_time_off_via_service(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $leave = TeacherUnavailability::factory()->create(['teacher_id' => $owner->id, 'timezone' => 'UTC']);

        try {
            app(InstructorTimeOffService::class)->delete($leave, $intruder);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('teacher_unavailability', ['id' => $leave->id]);
    }

    public function test_non_instructor_actor_cannot_manage_availability_via_service(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student->assignRole('student');

        $this->expectException(AuthorizationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $student->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $student);
    }

    // ── Cross-user denial (Livewire) ──────────────────────────────────

    public function test_livewire_cannot_toggle_or_delete_another_instructors_availability_window(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $window = TeacherAvailability::factory()->create(['teacher_id' => $owner->id, 'timezone' => 'UTC']);

        try {
            Livewire::actingAs($intruder)
                ->test(AvailabilityManager::class)
                ->call('deleteWindow', $window->id);
            $this->fail('Expected ModelNotFoundException.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('teacher_availability', ['id' => $window->id]);
    }

    public function test_livewire_cannot_delete_another_instructors_time_off(): void
    {
        $owner = $this->instructor();
        $intruder = $this->instructor();
        $leave = TeacherUnavailability::factory()->create(['teacher_id' => $owner->id, 'timezone' => 'UTC']);

        try {
            Livewire::actingAs($intruder)
                ->test(AvailabilityManager::class)
                ->call('deleteTimeOff', $leave->id);
            $this->fail('Expected ModelNotFoundException.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('teacher_unavailability', ['id' => $leave->id]);
    }

    // ── Admin permission tests ─────────────────────────────────────────

    public function test_permitted_admin_can_create_update_delete_availability_through_service(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: true);
        $teacher = $this->instructor();

        $service = app(InstructorAvailabilityService::class);

        $window = $service->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $admin);
        $this->assertDatabaseHas('teacher_availability', ['id' => $window->id]);

        $updated = $service->update($window, ['start_time' => '08:00'], $admin);
        $this->assertSame('08:00:00', $updated->start_time);

        $service->delete($updated, $admin);
        $this->assertDatabaseMissing('teacher_availability', ['id' => $window->id]);
    }

    public function test_non_permitted_admin_cannot_create_update_delete_availability_through_service(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: false);
        $teacher = $this->instructor();
        $window = TeacherAvailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        $service = app(InstructorAvailabilityService::class);

        try {
            $service->create([
                'teacher_id' => $teacher->id,
                'day_of_week' => Weekday::Tuesday,
                'start_time' => '09:00',
                'end_time' => '11:00',
                'is_active' => true,
            ], $admin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        try {
            $service->update($window, ['start_time' => '07:00'], $admin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        try {
            $service->delete($window, $admin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('teacher_availability', ['id' => $window->id]);
    }

    public function test_permitted_admin_can_create_update_delete_time_off_through_service(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: true);
        $teacher = $this->instructor();

        $service = app(InstructorTimeOffService::class);

        $leave = $service->create([
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-08-01 09:00:00',
            'ends_at' => '2026-08-01 10:00:00',
            'timezone' => 'UTC',
        ], $admin);
        $this->assertDatabaseHas('teacher_unavailability', ['id' => $leave->id]);

        $updated = $service->update($leave, ['reason' => 'Updated by admin'], $admin);
        $this->assertSame('Updated by admin', $updated->reason);

        $service->delete($updated, $admin);
        $this->assertDatabaseMissing('teacher_unavailability', ['id' => $leave->id]);
    }

    public function test_non_permitted_admin_cannot_create_update_delete_time_off_through_service(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: false);
        $teacher = $this->instructor();
        $leave = TeacherUnavailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        $service = app(InstructorTimeOffService::class);

        try {
            $service->create([
                'teacher_id' => $teacher->id,
                'starts_at' => '2026-09-01 09:00:00',
                'ends_at' => '2026-09-01 10:00:00',
                'timezone' => 'UTC',
            ], $admin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        try {
            $service->delete($leave, $admin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('teacher_unavailability', ['id' => $leave->id]);
    }

    // ── Filament: service-backed delete / bulk delete ───────────────────

    public function test_filament_row_delete_action_is_hidden_without_permission(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: false);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['ViewAny:TeacherAvailability', 'View:TeacherAvailability'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $admin->givePermissionTo(['ViewAny:TeacherAvailability', 'View:TeacherAvailability']);

        $teacher = $this->instructor();
        $window = TeacherAvailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        Livewire::actingAs($admin)
            ->test(ListTeacherAvailability::class)
            ->assertTableActionHidden('delete', record: $window);
    }

    public function test_filament_row_delete_action_is_service_backed_for_availability(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: true);
        $teacher = $this->instructor();
        $window = TeacherAvailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        Livewire::actingAs($admin)
            ->test(ListTeacherAvailability::class)
            ->callTableAction('delete', record: $window);

        $this->assertDatabaseMissing('teacher_availability', ['id' => $window->id]);

        $activity = Activity::where('log_name', 'teacher_availability')
            ->where('event', 'deleted')
            ->where('description', 'Instructor availability window deleted.')
            ->first();

        $this->assertNotNull(
            $activity,
            'Expected the service-backed audit trail entry (exact description) for the deleted availability window, not just any activity log row.'
        );
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($teacher->id, $activity->properties['teacher_id']);
    }

    public function test_filament_bulk_delete_action_is_service_backed_for_availability(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: true);
        $teacher = $this->instructor();
        $windows = TeacherAvailability::factory()->count(2)->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        Livewire::actingAs($admin)
            ->test(ListTeacherAvailability::class)
            ->callTableBulkAction('delete', $windows);

        $this->assertDatabaseCount('teacher_availability', 0);
        $this->assertSame(
            2,
            Activity::where('log_name', 'teacher_availability')
                ->where('event', 'deleted')
                ->where('description', 'Instructor availability window deleted.')
                ->count(),
        );
    }

    public function test_filament_row_delete_action_is_service_backed_for_time_off(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: true);
        $teacher = $this->instructor();
        $leave = TeacherUnavailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        Livewire::actingAs($admin)
            ->test(ListTeacherLeave::class)
            ->callTableAction('delete', record: $leave);

        $this->assertDatabaseMissing('teacher_unavailability', ['id' => $leave->id]);

        $activity = Activity::where('log_name', 'teacher_unavailability')
            ->where('event', 'deleted')
            ->where('description', 'Instructor time off deleted.')
            ->first();

        $this->assertNotNull(
            $activity,
            'Expected the service-backed audit trail entry (exact description) for the deleted time off, not just any activity log row.'
        );
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($teacher->id, $activity->properties['teacher_id']);
    }

    public function test_filament_bulk_delete_action_is_service_backed_for_time_off(): void
    {
        $admin = $this->manager(withAvailabilityPermissions: true);
        $teacher = $this->instructor();
        $leaves = TeacherUnavailability::factory()->count(2)->create(['teacher_id' => $teacher->id, 'timezone' => 'UTC']);

        Livewire::actingAs($admin)
            ->test(ListTeacherLeave::class)
            ->callTableBulkAction('delete', $leaves);

        $this->assertDatabaseCount('teacher_unavailability', 0);
        $this->assertSame(
            2,
            Activity::where('log_name', 'teacher_unavailability')
                ->where('event', 'deleted')
                ->where('description', 'Instructor time off deleted.')
                ->count(),
        );
    }

    // ── Frontend UI operations ──────────────────────────────────────────

    public function test_instructor_can_toggle_own_availability_active_state_from_ui(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');
        $window = TeacherAvailability::factory()->create([
            'teacher_id' => $teacher->id,
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ]);

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->call('toggleWindow', $window->id);

        $this->assertFalse($window->refresh()->is_active);
    }

    public function test_instructor_can_delete_own_weekly_availability_from_ui(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');
        $window = TeacherAvailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'Asia/Kolkata']);

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->call('deleteWindow', $window->id);

        $this->assertDatabaseMissing('teacher_availability', ['id' => $window->id]);
    }

    public function test_instructor_can_create_own_time_off_from_ui(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->set('timezone', 'Asia/Kolkata')
            ->set('timeOffStartsAt', '2026-08-10 09:00:00')
            ->set('timeOffEndsAt', '2026-08-10 10:00:00')
            ->set('timeOffReason', 'Doctor appointment')
            ->call('addTimeOff')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teacher_unavailability', [
            'teacher_id' => $teacher->id,
            'timezone' => 'Asia/Kolkata',
            'reason' => 'Doctor appointment',
        ]);
    }

    public function test_instructor_can_delete_own_time_off_from_ui(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');
        $leave = TeacherUnavailability::factory()->create(['teacher_id' => $teacher->id, 'timezone' => 'Asia/Kolkata']);

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->call('deleteTimeOff', $leave->id);

        $this->assertDatabaseMissing('teacher_unavailability', ['id' => $leave->id]);
    }

    public function test_ui_shows_validation_error_for_invalid_weekly_range(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->set('timezone', 'Asia/Kolkata')
            ->set('startTime', '11:00')
            ->set('endTime', '09:00')
            ->call('addWindow')
            ->assertHasErrors(['endTime']);

        $this->assertDatabaseCount('teacher_availability', 0);
    }

    public function test_ui_shows_validation_error_for_invalid_time_off_range(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->set('timezone', 'Asia/Kolkata')
            ->set('timeOffStartsAt', '2026-08-10 11:00:00')
            ->set('timeOffEndsAt', '2026-08-10 09:00:00')
            ->call('addTimeOff')
            ->assertHasErrors(['timeOffEndsAt']);

        $this->assertDatabaseCount('teacher_unavailability', 0);
    }

    public function test_missing_timezone_warning_appears_and_blocks_publish(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, timezone: null);

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->assertSet('hasProfileTimezone', false)
            ->assertSet('timezone', null)
            ->set('startTime', '09:00')
            ->set('endTime', '11:00')
            ->call('addWindow')
            ->assertHasErrors(['timezone']);

        $this->assertDatabaseCount('teacher_availability', 0);

        $this->get(route('dashboard.instructor.availability'))
            ->assertOk()
            ->assertSee('Your profile timezone is not set.');
    }

    public function test_instructor_with_timezone_publishes_availability_normally(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->assertSet('hasProfileTimezone', true)
            ->set('dayOfWeek', Weekday::Wednesday->value)
            ->set('startTime', '09:00')
            ->set('endTime', '11:00')
            ->call('addWindow')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teacher_availability', [
            'teacher_id' => $teacher->id,
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ]);
    }

    public function test_service_blocks_publish_without_profile_timezone_or_explicit_choice(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, timezone: null);

        $this->expectException(ValidationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $teacher);
    }

    public function test_service_allows_draft_creation_without_profile_timezone(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, timezone: null);

        $availability = app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => false,
        ], $teacher);

        $this->assertFalse($availability->is_active);
        $this->assertNotNull($availability->timezone);
    }

    // ── Out-of-scope record creation ────────────────────────────────────

    public function test_availability_operations_and_slot_generation_create_no_out_of_scope_records(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'UTC');
        BookingType::factory()->create([
            'key' => 'free_demo',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'max_attendees' => 1,
        ]);

        $bookingsBefore = Booking::count();
        $homeworkBefore = HomeworkAssignment::count();
        $reviewsBefore = LearningPlanReview::count();

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'timezone' => 'UTC',
            'is_active' => true,
        ], $teacher);

        app(InstructorTimeOffService::class)->create([
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-08-10 09:00:00',
            'ends_at' => '2026-08-10 10:00:00',
            'timezone' => 'UTC',
        ], $teacher);

        app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            hostId: $teacher->id,
            typeKey: 'free_demo',
            from: CarbonImmutable::parse('2026-08-10 00:00:00', 'UTC'),
            to: CarbonImmutable::parse('2026-08-17 00:00:00', 'UTC'),
            timezone: 'UTC',
        ));

        $this->assertSame($bookingsBefore, Booking::count());
        $this->assertSame($homeworkBefore, HomeworkAssignment::count());
        $this->assertSame($reviewsBefore, LearningPlanReview::count());

        // wallets/wallet_ledger_entries are the approved Phase 9 foundation;
        // no dedicated payment/meeting/reservation/slot table exists in this schema.
        foreach (['payments', 'meetings', 'reservations', 'slots', 'generated_slots', 'booking_slots'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found — availability hardening must not introduce out-of-scope structures.");
        }
    }

    // ── DST / edge-day coverage ──────────────────────────────────────────

    /**
     * Finds the DST transition dynamically (rather than hardcoding a
     * calendar date) so this test keeps working regardless of when it
     * runs, as long as a Southern Hemisphere transition falls inside the
     * booking engine's max-advance window relative to "now".
     */
    public function test_slot_generation_handles_dst_spring_forward_transition(): void
    {
        $timezone = 'Australia/Sydney';
        $tz = new \DateTimeZone($timezone);
        $now = CarbonImmutable::now($timezone);

        $transitions = $tz->getTransitions($now->getTimestamp(), $now->addDays(89)->getTimestamp());
        // Only a "spring forward" (start of DST) transition — the offset strictly
        // increases — keeps the assertions below unambiguous in both hemispheres.
        $transition = collect($transitions)->first(fn (array $t): bool => $t['ts'] > $now->getTimestamp() && $t['isdst'] === true);

        if ($transition === null) {
            $this->markTestSkipped('No DST transition falls inside the bookable window for the current date; non-blocking per Phase 6.3 docs.');
        }

        $transitionInstant = CarbonImmutable::createFromTimestamp($transition['ts'], 'UTC')->timezone($timezone);
        $dstSunday = $transitionInstant->next(CarbonImmutable::SUNDAY)->startOfDay();
        if ($transitionInstant->isSunday()) {
            $dstSunday = $transitionInstant->startOfDay();
        }
        $standardSunday = $dstSunday->subWeek();

        $teacher = $this->instructor(InstructorStatus::Approved, $timezone);
        BookingType::factory()->create([
            'key' => 'free_demo',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'max_attendees' => 1,
        ]);

        TeacherAvailability::factory()->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Sunday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'timezone' => $timezone,
        ]);

        $dstSlots = app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            hostId: $teacher->id,
            typeKey: 'free_demo',
            from: $dstSunday,
            to: $dstSunday->addDay(),
            timezone: 'UTC',
        ));

        $standardSlots = app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            hostId: $teacher->id,
            typeKey: 'free_demo',
            from: $standardSunday,
            to: $standardSunday->addDay(),
            timezone: 'UTC',
        ));

        $this->assertNotNull($dstSlots->first(), 'Expected a slot on the DST-transitioned Sunday.');
        $this->assertNotNull($standardSlots->first(), 'Expected a slot on the standard-time Sunday.');

        // Local wall-clock start time is identical either side of the transition...
        $this->assertSame(
            $dstSunday->format('Y-m-d').' 09:00',
            $dstSlots->first()->startsAt->timezone($timezone)->format('Y-m-d H:i'),
        );
        $this->assertSame(
            $standardSunday->format('Y-m-d').' 09:00',
            $standardSlots->first()->startsAt->timezone($timezone)->format('Y-m-d H:i'),
        );

        // ...but the UTC offset used to expand the local window shifts by
        // exactly one hour across the transition, proving slot generation
        // applies the correct per-instant offset rather than a fixed one.
        $standardOffset = $standardSlots->first()->startsAt->timezone($timezone)->getOffset();
        $dstOffset = $dstSlots->first()->startsAt->timezone($timezone)->getOffset();
        $this->assertSame(3600, $dstOffset - $standardOffset);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function instructor(InstructorStatus $status = InstructorStatus::Approved, ?string $timezone = 'UTC'): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $user->assignRole('instructor');

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'instructor_status' => $status,
                'profile_visibility' => 'public',
                'timezone' => $timezone,
            ],
        );

        return $user->refresh();
    }

    private function manager(bool $withAvailabilityPermissions): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');

        if ($withAvailabilityPermissions) {
            foreach ([
                'ViewAny:TeacherAvailability', 'View:TeacherAvailability', 'Create:TeacherAvailability', 'Update:TeacherAvailability', 'Delete:TeacherAvailability',
                'ViewAny:TeacherUnavailability', 'View:TeacherUnavailability', 'Create:TeacherUnavailability', 'Update:TeacherUnavailability', 'Delete:TeacherUnavailability',
            ] as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            $manager->givePermissionTo([
                'ViewAny:TeacherAvailability', 'View:TeacherAvailability', 'Create:TeacherAvailability', 'Update:TeacherAvailability', 'Delete:TeacherAvailability',
                'ViewAny:TeacherUnavailability', 'View:TeacherUnavailability', 'Create:TeacherUnavailability', 'Update:TeacherUnavailability', 'Delete:TeacherUnavailability',
            ]);
        }

        return $manager->refresh();
    }
}
