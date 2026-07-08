<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Instructor\AvailabilityManager;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Services\Instructor\InstructorTimeOffService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_creates_timezone_scoped_availability_for_bookable_instructor(): void
    {
        $actor = $this->permittedAdmin();
        $teacher = $this->instructor(InstructorStatus::Approved, 'America/New_York');

        $availability = app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $actor);

        $this->assertSame($teacher->id, $availability->teacher_id);
        $this->assertSame('America/New_York', $availability->timezone);
        $this->assertSame($actor->id, $availability->created_by);
        $this->assertSame($actor->id, $availability->updated_by);
    }

    public function test_service_rejects_published_availability_for_non_bookable_instructor(): void
    {
        $actor = $this->permittedAdmin();
        $teacher = $this->instructor(InstructorStatus::Suspended);

        $this->expectException(ValidationException::class);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $actor);
    }

    public function test_service_rejects_overlapping_active_windows(): void
    {
        $actor = $this->permittedAdmin();
        $teacher = $this->instructor();
        $service = app(InstructorAvailabilityService::class);

        $service->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_active' => true,
        ], $actor);

        $this->expectException(ValidationException::class);

        $service->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '10:30',
            'end_time' => '12:00',
            'is_active' => true,
        ], $actor);
    }

    public function test_slot_generation_expands_weekly_window_in_instructor_timezone(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'America/New_York');
        BookingType::factory()->create([
            'key' => 'free_demo',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'max_attendees' => 1,
        ]);

        TeacherAvailability::factory()
            ->state([
                'teacher_id' => $teacher->id,
                'day_of_week' => Weekday::Monday,
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
                'timezone' => 'America/New_York',
            ])
            ->create();

        $slots = app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            hostId: $teacher->id,
            typeKey: 'free_demo',
            from: CarbonImmutable::parse('2026-07-13 00:00:00', 'America/New_York'),
            to: CarbonImmutable::parse('2026-07-14 00:00:00', 'America/New_York'),
            timezone: 'America/New_York',
        ));

        $this->assertSame('2026-07-13 09:00', $slots->first()->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-07-13 10:00', $slots->first()->endsAt->format('Y-m-d H:i'));
    }

    public function test_time_off_service_stores_local_input_as_utc_and_blocks_slots(): void
    {
        $actor = $this->permittedAdmin();
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');
        BookingType::factory()->create([
            'key' => 'free_demo',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'max_attendees' => 1,
        ]);
        TeacherAvailability::factory()
            ->state([
                'teacher_id' => $teacher->id,
                'day_of_week' => Weekday::Monday,
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
                'timezone' => 'Asia/Kolkata',
            ])
            ->create();

        $leave = app(InstructorTimeOffService::class)->create([
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-07-13 09:00:00',
            'ends_at' => '2026-07-13 10:00:00',
            'timezone' => 'Asia/Kolkata',
            'reason' => 'Personal appointment',
        ], $actor);

        $this->assertInstanceOf(TeacherUnavailability::class, $leave);
        $this->assertSame('2026-07-13 03:30', $leave->starts_at->format('Y-m-d H:i'));

        $slots = app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            hostId: $teacher->id,
            typeKey: 'free_demo',
            from: CarbonImmutable::parse('2026-07-13 00:00:00', 'Asia/Kolkata'),
            to: CarbonImmutable::parse('2026-07-14 00:00:00', 'Asia/Kolkata'),
            timezone: 'Asia/Kolkata',
        ));

        $this->assertSame(['10:00'], $slots->map(fn ($slot): string => $slot->startsAt->format('H:i'))->all());
    }

    public function test_instructor_can_manage_availability_from_frontend_page(): void
    {
        $teacher = $this->instructor(InstructorStatus::Approved, 'Asia/Kolkata');

        $this->actingAs($teacher)
            ->get(route('dashboard.instructor.availability'))
            ->assertOk()
            ->assertSee('Teaching Availability');

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->set('timezone', 'Asia/Kolkata')
            ->set('dayOfWeek', Weekday::Tuesday->value)
            ->set('startTime', '15:00')
            ->set('endTime', '17:00')
            ->call('addWindow')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teacher_availability', [
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Tuesday->value,
            'start_time' => '15:00:00',
            'end_time' => '17:00:00',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ]);
    }

    private function instructor(InstructorStatus $status = InstructorStatus::Approved, string $timezone = 'UTC'): User
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

    private function permittedAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        foreach (['Create:TeacherAvailability', 'Update:TeacherAvailability', 'Delete:TeacherAvailability', 'Create:TeacherUnavailability', 'Update:TeacherUnavailability', 'Delete:TeacherUnavailability'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin->givePermissionTo([
            'Create:TeacherAvailability', 'Update:TeacherAvailability', 'Delete:TeacherAvailability',
            'Create:TeacherUnavailability', 'Update:TeacherUnavailability', 'Delete:TeacherUnavailability',
        ]);

        return $admin;
    }
}
