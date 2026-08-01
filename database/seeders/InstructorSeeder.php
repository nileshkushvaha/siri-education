<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds a handful of approved, publicly bookable instructors so the guest
 * booking wizard (/book) has real subjects and availability to display.
 * Without this, teacher_subjects is empty and the wizard's "Choose a
 * subject" step has nothing to show.
 */
class InstructorSeeder extends Seeder
{
    private const INSTRUCTORS = [
        ['name' => 'Alice Turner', 'subjects' => ['Algebra', 'Geometry']],
        ['name' => 'Marcus Chen', 'subjects' => ['Physics', 'Chemistry']],
        ['name' => 'Priya Nair', 'subjects' => ['English', 'Creative Writing']],
        ['name' => 'David Okafor', 'subjects' => ['Programming Fundamentals', 'Web Development']],
        ['name' => 'Sofia Ramirez', 'subjects' => ['SAT Prep', 'Statistics']],
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

            foreach ($data['subjects'] as $subjectName) {
                $subject = Subject::query()->where('name', $subjectName)->first();

                TeacherSubject::query()->firstOrCreate(
                    ['teacher_id' => $user->id, 'subject' => strtolower($subjectName)],
                    [
                        'subject_id' => $subject?->id,
                        'grade_from' => 1,
                        'grade_to' => 12,
                    ],
                );
            }

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
