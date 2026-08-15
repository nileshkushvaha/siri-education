<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Models\TeacherAvailability;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds approved demo instructors and their availability. Academic subject
 * assignments are owned by InstructorSubjectAssignmentSeeder so the demo
 * accounts always follow the current catalogue.
 */
class InstructorSeeder extends Seeder
{
    private const INSTRUCTORS = [
        ['name' => 'Alice Turner'],
        ['name' => 'Marcus Chen'],
        ['name' => 'Priya Nair'],
        ['name' => 'David Okafor'],
        ['name' => 'Sofia Ramirez'],
    ];

    public function run(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        foreach (self::INSTRUCTORS as $i => $data) {
            $email = 'instructor'.($i + 1).'@example.com';

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $data['name'],
                    'status' => 'active',
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ],
            );

            if (! $user->hasRole('instructor')) {
                $user->assignRole('instructor');
            }

            $user->profile()->updateOrCreate(['user_id' => $user->id], [
                'profile_visibility' => 'public',
                'instructor_status' => InstructorStatus::Approved,
                'is_instructor_verified' => true,
            ]);

            foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
                TeacherAvailability::query()->firstOrCreate([
                    'teacher_id' => $user->id,
                    'day_of_week' => $day,
                ], [
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'is_active' => true,
                ]);
            }
        }
    }
}
