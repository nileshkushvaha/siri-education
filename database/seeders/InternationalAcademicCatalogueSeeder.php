<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\CountryEducationSystem;
use App\Models\Curriculum;
use App\Models\CurriculumEducationSystem;
use App\Models\CurriculumModule;
use App\Models\CurriculumModuleTopic;
use App\Models\CurriculumVersion;
use App\Models\EducationSystem;
use App\Models\EducationSystemAcademicLevel;
use App\Models\EducationSystemLevel;
use App\Models\InstructorSubjectTopic;
use App\Models\PackageBenefitRule;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a coherent Grade 6-12 catalogue for the platform's initial
 * international markets. The data is deliberately generic enough to be a
 * useful starting point; admins can refine individual curriculum versions in
 * the UI without this seeder pretending to reproduce an official syllabus.
 */
final class InternationalAcademicCatalogueSeeder extends Seeder
{
    /** @var array<string, array<string, list<string>>> */
    private const SUBJECTS = [
        'Mathematics' => [
            'Mathematics' => ['Number Systems', 'Algebra', 'Geometry', 'Mensuration', 'Data Handling and Probability'],
            'Statistics' => ['Data Collection', 'Data Representation', 'Probability', 'Descriptive Statistics', 'Statistical Inference'],
            'Further Mathematics' => ['Advanced Algebra', 'Trigonometry', 'Calculus', 'Vectors and Matrices', 'Complex Numbers'],
            'Applied Mathematics' => ['Mathematical Modelling', 'Financial Mathematics', 'Mechanics', 'Optimization', 'Numerical Methods'],
        ],
        'Sciences' => [
            'General Science' => ['Scientific Method', 'Matter and Materials', 'Energy and Forces', 'Living Systems', 'Earth and Space'],
            'Physics' => ['Motion and Forces', 'Work, Energy and Power', 'Waves and Optics', 'Electricity and Magnetism', 'Modern Physics'],
            'Chemistry' => ['Atomic Structure', 'Chemical Bonding', 'Chemical Reactions', 'Acids, Bases and Salts', 'Organic Chemistry'],
            'Biology' => ['Cell Biology', 'Human Physiology', 'Genetics and Evolution', 'Ecology', 'Plant Biology'],
            'Environmental Science' => ['Ecosystems', 'Natural Resources', 'Pollution', 'Climate and Sustainability', 'Conservation'],
            'Earth Science' => ['Geology', 'Meteorology', 'Oceanography', 'Earth Systems', 'Natural Hazards'],
            'Astronomy' => ['Solar System', 'Stars and Galaxies', 'Space Exploration', 'Observational Astronomy', 'Cosmology'],
        ],
        'Languages' => [
            'English' => ['Reading Comprehension', 'Grammar and Vocabulary', 'Writing', 'Literature', 'Speaking and Listening'],
            'Hindi' => ['Reading Comprehension', 'Grammar', 'Writing', 'Literature', 'Speaking and Listening'],
        ],
        'Computer Science' => [
            'Computer Science' => ['Computational Thinking', 'Programming Fundamentals', 'Data and Algorithms', 'Computer Systems and Networks', 'Cyber Safety and Digital Citizenship'],
            'Information Technology' => ['Digital Productivity', 'Databases', 'Web Technologies', 'Networking', 'Information Security'],
            'Artificial Intelligence' => ['AI Foundations', 'Data Literacy', 'Machine Learning Concepts', 'Natural Language and Vision', 'Responsible AI'],
            'Robotics' => ['Robotics Foundations', 'Electronics and Sensors', 'Programming Robots', 'Mechanisms and Control', 'Robotics Projects'],
        ],
        'Commerce' => [
            'Accounting' => ['Accounting Principles', 'Journals and Ledgers', 'Financial Statements', 'Cost Accounting', 'Financial Analysis'],
            'Commerce' => ['Trade and Business', 'Banking and Insurance', 'Business Communication', 'Consumer Awareness', 'International Trade'],
            'Finance' => ['Personal Finance', 'Financial Markets', 'Banking', 'Investment Fundamentals', 'Risk and Insurance'],
            'Entrepreneurship' => ['Opportunity Identification', 'Business Models', 'Business Planning', 'Funding and Finance', 'Launching a Venture'],
            'Legal Studies' => ['Legal Systems', 'Rights and Responsibilities', 'Civil and Criminal Law', 'Business Law', 'Law and Society'],
        ],
    ];

    /**
     * These countries exist in CountrySeeder. Additional markets are included
     * only where the product's English-language Grade 6-12 catalogue is usable.
     *
     * @var array<string, array{name: string, code: string, term: string}>
     */
    private const SYSTEMS = [
        'IN' => ['name' => 'India Secondary Education', 'code' => 'IN-SEC', 'term' => 'Class'],
        'US' => ['name' => 'United States K-12', 'code' => 'US-K12', 'term' => 'Grade'],
        'GB' => ['name' => 'United Kingdom Secondary Education', 'code' => 'UK-SEC', 'term' => 'Year'],
        'AU' => ['name' => 'Australian Curriculum', 'code' => 'AU-AC', 'term' => 'Year'],
        'CA' => ['name' => 'Canadian Secondary Education', 'code' => 'CA-SEC', 'term' => 'Grade'],
        'AE' => ['name' => 'United Arab Emirates Secondary Education', 'code' => 'AE-SEC', 'term' => 'Grade'],
        'SG' => ['name' => 'Singapore Secondary Education', 'code' => 'SG-SEC', 'term' => 'Grade'],
        'NZ' => ['name' => 'New Zealand Curriculum', 'code' => 'NZ-NC', 'term' => 'Year'],
        'SA' => ['name' => 'Saudi Arabian Secondary Education', 'code' => 'SA-SEC', 'term' => 'Grade'],
    ];

    private const PACKAGES = [
        ['Starter — 5 Lessons', 5, 0, 30],
        ['Progress — 10 + 1 Bonus Lessons', 10, 1, 60],
        ['Achievement — 20 + 2 Bonus Lessons', 20, 2, 120],
        ['Term Support — 40 + 5 Bonus Lessons', 40, 5, 180],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $subjects = $this->seedSubjectsAndTopics();
            $levels = $this->seedAcademicLevels();
            $systems = $this->seedEducationSystems($levels);

            $this->seedCurricula($subjects, $levels, $systems);
            $this->seedInstructorTopicCoverage($subjects, $levels);
            $this->seedPackageOffers();
        });

        $this->command?->info('✓ International Grade 6-12 academic catalogue seeded.');
    }

    /** @return array<string, Subject> */
    private function seedSubjectsAndTopics(): array
    {
        $subjects = [];

        foreach (self::SUBJECTS as $categoryName => $categorySubjects) {
            $categoryOrder = array_search($categoryName, array_keys(self::SUBJECTS), true);
            $category = AcademicCategory::withTrashed()->updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'display_order' => $categoryOrder, 'is_active' => true, 'deleted_at' => null],
            );

            foreach ($categorySubjects as $subjectName => $topics) {
                $subjectOrder = array_search($subjectName, array_keys($categorySubjects), true);
                $subject = Subject::withTrashed()->updateOrCreate(
                    ['slug' => Str::slug($subjectName)],
                    [
                        'academic_category_id' => $category->id,
                        'name' => $subjectName,
                        'status' => 'active',
                        'display_order' => $subjectOrder,
                        'deleted_at' => null,
                    ],
                );
                $subjects[$subjectName] = $subject;

                foreach ($topics as $topicOrder => $topicName) {
                    SubjectTopic::withTrashed()->updateOrCreate(
                        ['subject_id' => $subject->id, 'slug' => Str::slug($topicName)],
                        [
                            'parent_id' => null,
                            'name' => $topicName,
                            'status' => 'active',
                            'display_order' => $topicOrder,
                            'deleted_at' => null,
                        ],
                    );
                }
            }
        }

        return $subjects;
    }

    /** @return array<string, AcademicLevel> */
    private function seedAcademicLevels(): array
    {
        $definitions = [
            'middle-school' => ['Middle School', 6, 8, 0],
            'high-school' => ['High School', 9, 12, 1],
        ];
        $levels = [];

        foreach ($definitions as $slug => [$name, $minGrade, $maxGrade, $order]) {
            $levels[$slug] = AcademicLevel::withTrashed()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'min_grade' => $minGrade,
                    'max_grade' => $maxGrade,
                    'country_id' => null,
                    'status' => 'active',
                    'display_order' => $order,
                    'deleted_at' => null,
                ],
            );
        }

        return $levels;
    }

    /**
     * @param  array<string, AcademicLevel>  $levels
     * @return array<string, EducationSystem>
     */
    private function seedEducationSystems(array $levels): array
    {
        $systems = [];

        foreach (self::SYSTEMS as $iso2 => $definition) {
            $order = array_search($iso2, array_keys(self::SYSTEMS), true);
            $country = Country::query()->where('iso2', $iso2)->first();

            if ($country === null) {
                $this->command?->warn("Skipping {$iso2}: run CountrySeeder first.");

                continue;
            }

            $system = EducationSystem::withTrashed()->updateOrCreate(
                ['slug' => Str::slug($definition['code'])],
                [
                    'name' => $definition['name'],
                    'code' => $definition['code'],
                    'description' => "Platform starter framework for Grades 6-12 in {$country->name}; refine curriculum content before production use.",
                    'status' => 'active',
                    'display_order' => $order,
                    'level_term_singular' => $definition['term'],
                    'level_term_plural' => Str::plural($definition['term']),
                    'deleted_at' => null,
                ],
            );
            $systems[$iso2] = $system;

            CountryEducationSystem::query()->updateOrCreate(
                ['country_id' => $country->id, 'education_system_id' => $system->id],
                ['is_active' => true, 'display_order' => 0],
            );

            foreach ($levels as $levelOrder => $level) {
                EducationSystemAcademicLevel::query()->updateOrCreate(
                    ['education_system_id' => $system->id, 'academic_level_id' => $level->id],
                    ['is_active' => true, 'display_order' => $levelOrder === 'middle-school' ? 0 : 1],
                );
            }

            for ($grade = 6; $grade <= 12; $grade++) {
                $level = $grade <= 8 ? $levels['middle-school'] : $levels['high-school'];
                EducationSystemLevel::withTrashed()->updateOrCreate(
                    ['education_system_id' => $system->id, 'value' => (string) $grade],
                    [
                        'academic_level_id' => $level->id,
                        'display_label' => "{$definition['term']} {$grade}",
                        'normalized_grade' => $grade,
                        'display_order' => $grade - 6,
                        'is_active' => true,
                        'deleted_at' => null,
                    ],
                );
            }
        }

        return $systems;
    }

    /**
     * @param  array<string, Subject>  $subjects
     * @param  array<string, AcademicLevel>  $levels
     * @param  array<string, EducationSystem>  $systems
     */
    private function seedCurricula(array $subjects, array $levels, array $systems): void
    {
        foreach ($systems as $system) {
            foreach ($subjects as $subject) {
                foreach ($levels as $level) {
                    $name = "{$system->name}: {$subject->name} ({$level->name})";
                    $curriculum = Curriculum::withTrashed()->updateOrCreate(
                        [
                            'subject_id' => $subject->id,
                            'academic_level_id' => $level->id,
                            'slug' => Str::slug($name),
                        ],
                        [
                            'name' => $name,
                            'description' => 'Grade-aligned starter curriculum. Review and expand its versioned modules before assigning it to a learning plan.',
                            'deleted_at' => null,
                        ],
                    );

                    CurriculumEducationSystem::query()->firstOrCreate([
                        'curriculum_id' => $curriculum->id,
                        'education_system_id' => $system->id,
                    ]);

                    $version = CurriculumVersion::withTrashed()->updateOrCreate(
                        ['curriculum_id' => $curriculum->id, 'version_number' => 1],
                        [
                            'status' => 'draft',
                            'notes' => 'Starter version seeded for administrator review. Add modules and publish only after academic validation.',
                            'published_at' => null,
                            'archived_at' => null,
                            'retired_at' => null,
                            'deleted_at' => null,
                        ],
                    );

                    $module = CurriculumModule::query()->updateOrCreate(
                        ['curriculum_version_id' => $version->id, 'title' => 'Core Topics'],
                        [
                            'description' => "Core {$subject->name} topic coverage for {$level->name}.",
                            'sort_order' => 0,
                        ],
                    );

                    foreach ($subject->topics()->active()->get() as $topicOrder => $topic) {
                        CurriculumModuleTopic::query()->updateOrCreate(
                            ['curriculum_module_id' => $module->id, 'subject_topic_id' => $topic->id],
                            ['sort_order' => $topicOrder],
                        );
                    }
                }
            }
        }
    }

    /**
     * Seeds approved coverage only where an instructor already has a
     * TeacherSubject assignment. It never invents instructor expertise.
     *
     * @param  array<string, Subject>  $subjects
     * @param  array<string, AcademicLevel>  $levels
     */
    private function seedInstructorTopicCoverage(array $subjects, array $levels): void
    {
        $approverId = User::role('super_admin')->value('id');

        TeacherSubject::query()
            ->whereNotNull('subject_id')
            ->with('teacher')
            ->get()
            ->each(function (TeacherSubject $assignment) use ($subjects, $levels, $approverId): void {
                $subject = collect($subjects)->firstWhere('id', $assignment->subject_id);

                if ($subject === null || $assignment->teacher === null || ! $assignment->teacher->hasRole('instructor')) {
                    return;
                }

                foreach ($subject->topics()->active()->get() as $topicOrder => $topic) {
                    foreach ($levels as $level) {
                        if ($assignment->grade_from > $level->max_grade || $assignment->grade_to < $level->min_grade) {
                            continue;
                        }

                        InstructorSubjectTopic::query()->updateOrCreate(
                            [
                                'teacher_id' => $assignment->teacher_id,
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
            });
    }

    private function seedPackageOffers(): void
    {
        foreach (self::PACKAGES as [$name, $paid, $bonus, $validityDays]) {
            PackageBenefitRule::withTrashed()->updateOrCreate(
                ['name' => $name],
                [
                    'paid_quantity' => $paid,
                    'bonus_quantity' => $bonus,
                    'total_quantity' => $paid + $bonus,
                    'validity_days' => $validityDays,
                    'is_active' => true,
                    'deleted_at' => null,
                ],
            );
        }
    }
}
