<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\InstructorCurriculumEligibility;
use App\Models\InstructorSubjectTopic;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Assigns the seeded Grade 6-12 catalogue to the known demo instructors.
 * Production instructor expertise remains managed through onboarding/admin.
 */
final class InstructorSubjectAssignmentSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const ASSIGNMENTS = [
        'instructor1@example.com' => [
            'Mathematics',
            'Statistics',
            'Further Mathematics',
            'Applied Mathematics',
        ],
        'instructor2@example.com' => [
            'General Science',
            'Physics',
            'Chemistry',
            'Biology',
            'Environmental Science',
            'Earth Science',
            'Astronomy',
        ],
        'instructor3@example.com' => [
            'English',
            'Hindi',
        ],
        'instructor4@example.com' => [
            'Computer Science',
            'Information Technology',
            'Artificial Intelligence',
            'Robotics',
        ],
        'instructor5@example.com' => [
            'Accounting',
            'Commerce',
            'Finance',
            'Entrepreneurship',
            'Legal Studies',
        ],
    ];

    public function run(): void
    {
        $approverId = User::role('super_admin')->value('id');
        $levels = AcademicLevel::query()
            ->active()
            ->whereIn('slug', ['middle-school', 'high-school'])
            ->get();

        foreach (self::ASSIGNMENTS as $email => $subjectNames) {
            $instructor = User::query()->where('email', $email)->first();

            if ($instructor === null || ! $instructor->hasRole('instructor')) {
                $this->command?->warn("Skipping {$email}: seeded instructor account not found.");

                continue;
            }

            $subjects = Subject::query()
                ->active()
                ->whereIn('name', $subjectNames)
                ->get()
                ->keyBy('name');

            foreach ($subjectNames as $subjectName) {
                $subject = $subjects->get($subjectName);

                if ($subject === null) {
                    $this->command?->warn("Skipping {$subjectName}: active subject not found.");

                    continue;
                }

                TeacherSubject::query()->updateOrCreate(
                    [
                        'teacher_id' => $instructor->id,
                        'subject' => $subject->name,
                    ],
                    [
                        'subject_id' => $subject->id,
                        'grade_from' => 6,
                        'grade_to' => 12,
                    ],
                );

                foreach ($subject->topics()->active()->get() as $topicOrder => $topic) {
                    foreach ($levels as $level) {
                        InstructorSubjectTopic::query()->updateOrCreate(
                            [
                                'teacher_id' => $instructor->id,
                                'subject_topic_id' => $topic->id,
                                'academic_level_id' => $level->id,
                            ],
                            [
                                'subject_id' => $subject->id,
                                'proficiency_level' => 'proficient',
                                'is_primary' => $topicOrder === 0,
                                'is_active' => true,
                                'approved_at' => now(),
                                'approved_by' => $approverId,
                            ],
                        );
                    }
                }

                Curriculum::query()
                    ->where('subject_id', $subject->id)
                    ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
                    ->with('educationSystemMappings')
                    ->get()
                    ->each(function (Curriculum $curriculum) use ($instructor, $approverId): void {
                        foreach ($curriculum->educationSystemMappings as $mapping) {
                            InstructorCurriculumEligibility::query()->updateOrCreate(
                                [
                                    'teacher_id' => $instructor->id,
                                    'education_system_id' => $mapping->education_system_id,
                                    'curriculum_id' => $curriculum->id,
                                ],
                                [
                                    'is_active' => true,
                                    'notes' => 'Seeded demo instructor eligibility for the Grade 6-12 catalogue.',
                                    'approved_at' => now(),
                                    'approved_by' => $approverId,
                                    'created_by' => $approverId,
                                    'updated_by' => $approverId,
                                ],
                            );
                        }
                    });
            }
        }

        $this->command?->info('✓ Demo instructor Grade 6-12 subject assignments seeded.');
    }
}
