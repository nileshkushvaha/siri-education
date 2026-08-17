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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Gives every instructor a complete set of academic records.
 *
 * Environment agnostic — it selects users by the `instructor` role, never by email, so it
 * behaves the same on local, staging and production where the accounts differ.
 *
 * Two passes:
 *
 *  1. Any instructor with NO subject assignments is given a random selection from the
 *     active catalogue. Instructors who already have subjects are left completely alone,
 *     so real onboarding/admin decisions are never overwritten — and because the pass only
 *     targets empty instructors, re-running never piles on more random subjects.
 *  2. Every instructor is then backfilled from `teacher_subjects`, creating the derived
 *     InstructorSubjectTopic (per topic x academic level) and InstructorCurriculumEligibility
 *     rows.
 *
 * Safe to re-run. Nothing is truncated or deleted. Academic levels come from each
 * assignment's own grade range via AcademicLevel::coversGrade(), so a primary-school
 * instructor is never handed high-school levels.
 */
final class InstructorSubjectAssignmentSeeder extends Seeder
{
    /**
     * Free-text values predating the catalogue, mapped to their canonical subject.
     *
     * These are topics or exam prep rather than subjects, so no name lookup can resolve
     * them. Keys are lowercased. `sat prep` is deliberately absent — it has no catalogue
     * equivalent and stays unmapped rather than being filed under a subject the
     * instructor may not actually teach.
     */
    private const LEGACY_SUBJECT_ALIASES = [
        'algebra' => 'Mathematics',
        'geometry' => 'Mathematics',
        'calculus' => 'Mathematics',
        'creative writing' => 'English',
        'programming fundamentals' => 'Computer Science',
        'web development' => 'Information Technology',
    ];

    /** How many catalogue subjects an instructor with none is given. */
    private const RANDOM_SUBJECTS_MIN = 2;

    private const RANDOM_SUBJECTS_MAX = 4;

    private const DEFAULT_GRADE_FROM = 6;

    private const DEFAULT_GRADE_TO = 12;

    /** User keys are UUID strings locally but integers on older databases. */
    private int|string|null $approverId = null;

    /** @var Collection<int, AcademicLevel> */
    private Collection $levels;

    private int $instructorCount = 0;

    private int $topicRows = 0;

    private int $eligibilityRows = 0;

    private int $skippedSubjects = 0;

    private int $randomlyAssigned = 0;

    private int $renamedRows = 0;

    private int $mergedRows = 0;

    public function run(): void
    {
        // Spatie's role() scope throws outright if the role does not exist, so both roles
        // are checked before any query uses them rather than aborting mid-seed.
        if (! $this->roleExists('instructor')) {
            $this->command?->warn('No `instructor` role found — nothing to seed.');

            return;
        }

        // Approval attribution is optional; fall back to null when no super admin exists.
        $this->approverId = $this->roleExists('super_admin')
            ? User::role('super_admin')->value('id')
            : null;
        $this->levels = AcademicLevel::query()->availableForAssignment()->get();

        if ($this->levels->isEmpty()) {
            $this->command?->warn('No active academic levels found — nothing to assign.');

            return;
        }

        $this->assignRandomSubjectsWhereMissing();
        $this->backfillEveryInstructor();

        $this->command?->info(sprintf(
            '✓ Instructor academic records synced — %d instructor(s), %d topic row(s), %d curriculum eligibility row(s).',
            $this->instructorCount,
            $this->topicRows,
            $this->eligibilityRows,
        ));

        if ($this->randomlyAssigned > 0) {
            $this->command?->info(sprintf(
                '  %d instructor(s) had no subjects and were given a random catalogue selection.',
                $this->randomlyAssigned,
            ));
        }

        if ($this->renamedRows > 0 || $this->mergedRows > 0) {
            $this->command?->info(sprintf(
                '  %d legacy row(s) renamed to their catalogue subject, %d merged into an existing row.',
                $this->renamedRows,
                $this->mergedRows,
            ));
        }

        if ($this->skippedSubjects > 0) {
            $this->command?->warn(sprintf(
                '%d subject assignment(s) skipped — no matching active subject in the catalogue.',
                $this->skippedSubjects,
            ));
        }
    }

    private function roleExists(string $name): bool
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->exists();
    }

    /**
     * Gives a random slice of the catalogue to instructors who have no subjects at all.
     *
     * Selection is by role, never by email, so it works on staging and production where
     * the accounts differ. Instructors that already hold assignments are skipped entirely
     * — which also makes this idempotent: a second run finds nobody empty and adds nothing.
     */
    private function assignRandomSubjectsWhereMissing(): void
    {
        $subjectIds = Subject::query()->active()->pluck('id');

        if ($subjectIds->isEmpty()) {
            $this->command?->warn('No active subjects in the catalogue — skipping random assignment.');

            return;
        }

        User::query()
            ->role('instructor')
            ->whereDoesntHave('teacherSubjects')
            ->orderBy('id')
            ->chunkById(50, function (Collection $instructors) use ($subjectIds): void {
                foreach ($instructors as $instructor) {
                    $take = min(
                        random_int(self::RANDOM_SUBJECTS_MIN, self::RANDOM_SUBJECTS_MAX),
                        $subjectIds->count(),
                    );

                    $subjects = Subject::query()
                        ->whereIn('id', $subjectIds->random($take)->all())
                        ->get();

                    DB::transaction(function () use ($instructor, $subjects): void {
                        foreach ($subjects as $subject) {
                            TeacherSubject::query()->updateOrCreate(
                                [
                                    'teacher_id' => $instructor->id,
                                    'subject' => $subject->name,
                                ],
                                [
                                    'subject_id' => $subject->id,
                                    'grade_from' => self::DEFAULT_GRADE_FROM,
                                    'grade_to' => self::DEFAULT_GRADE_TO,
                                ],
                            );
                        }
                    });

                    $this->randomlyAssigned++;
                }
            });
    }

    /**
     * Walks every instructor that has at least one subject assignment. Chunked so a large
     * production roster never loads into memory at once, and wrapped per instructor so one
     * bad record cannot roll back everybody else's work.
     */
    private function backfillEveryInstructor(): void
    {
        User::query()
            ->role('instructor')
            ->whereHas('teacherSubjects')
            ->orderBy('id')
            ->chunkById(50, function (Collection $instructors): void {
                foreach ($instructors as $instructor) {
                    DB::transaction(fn () => $this->syncInstructor($instructor));
                    $this->instructorCount++;
                }
            });
    }

    private function syncInstructor(User $instructor): void
    {
        $assignments = TeacherSubject::query()
            ->where('teacher_id', $instructor->id)
            ->get();

        foreach ($assignments as $assignment) {
            $subject = $this->resolveSubject($assignment);

            if ($subject === null) {
                $this->skippedSubjects++;

                continue;
            }

            // Repairs legacy rows: canonical name + subject_id, merging any duplicate.
            $assignment = $this->normaliseAssignment($assignment, $subject);

            if ($assignment === null) {
                continue;
            }

            $levels = $this->levelsForAssignment($assignment);

            if ($levels->isEmpty()) {
                continue;
            }

            $this->syncTopics($instructor, $subject, $levels);
            $this->syncCurriculumEligibility($instructor, $subject);
        }
    }

    /**
     * Assignments carry a subject_id once backfilled, but older rows only have the name.
     */
    private function resolveSubject(TeacherSubject $assignment): ?Subject
    {
        if (filled($assignment->subject_id)) {
            return Subject::query()->active()->find($assignment->subject_id);
        }

        $name = trim((string) $assignment->subject);

        if ($name === '') {
            return null;
        }

        // Legacy rows were free text, so casing and spacing drift from the catalogue
        // ("physics" vs "Physics"). Compare lowercased rather than relying on the
        // column collation, which is case-sensitive on some deployments.
        $direct = Subject::query()
            ->active()
            ->where(function ($query) use ($name): void {
                $query
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
                    ->orWhere('slug', Str::slug($name));
            })
            ->first();

        if ($direct !== null) {
            return $direct;
        }

        $alias = self::LEGACY_SUBJECT_ALIASES[mb_strtolower($name)] ?? null;

        return $alias === null
            ? null
            : Subject::query()->active()->where('name', $alias)->first();
    }

    /**
     * Brings a legacy row onto the canonical subject.
     *
     * Candidate matching runs off the `subject` name column, not `subject_id`, so setting
     * the key alone would leave the instructor unbookable. The name is rewritten too —
     * and because (teacher_id, subject, grade_from, grade_to) is unique, a row that would
     * collide with an already-canonical row is merged away instead.
     *
     * @return TeacherSubject|null null when the row was merged into an existing one
     */
    private function normaliseAssignment(TeacherSubject $assignment, Subject $subject): ?TeacherSubject
    {
        if ($assignment->subject !== $subject->name) {
            $existing = TeacherSubject::query()
                ->where('teacher_id', $assignment->teacher_id)
                ->where('subject', $subject->name)
                ->where('grade_from', $assignment->grade_from)
                ->where('grade_to', $assignment->grade_to)
                ->whereKeyNot($assignment->getKey())
                ->exists();

            if ($existing) {
                // The canonical row is already present (or was renamed earlier in this
                // same pass), so this alias row is redundant.
                $assignment->delete();
                $this->mergedRows++;

                return null;
            }

            $assignment->forceFill(['subject' => $subject->name]);
            $this->renamedRows++;
        }

        if ($assignment->subject_id !== $subject->id) {
            $assignment->forceFill(['subject_id' => $subject->id]);
        }

        if ($assignment->isDirty()) {
            $assignment->save();
        }

        return $assignment;
    }

    /**
     * Levels come from the assignment's own grade range rather than a fixed pair of
     * slugs, so a primary-school instructor is not handed high-school levels.
     *
     * @return Collection<int, AcademicLevel>
     */
    private function levelsForAssignment(TeacherSubject $assignment): Collection
    {
        $from = $assignment->grade_from;
        $to = $assignment->grade_to;

        if ($from === null && $to === null) {
            // Not grade-bound: fall back to every grade-bound level.
            return $this->levels->filter(
                fn (AcademicLevel $level) => $level->min_grade !== null || $level->max_grade !== null
            )->values();
        }

        $from ??= $to;
        $to ??= $from;

        return $this->levels->filter(function (AcademicLevel $level) use ($from, $to): bool {
            foreach (range((int) $from, (int) $to) as $grade) {
                if ($level->coversGrade($grade)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @param  Collection<int, AcademicLevel>  $levels
     */
    private function syncTopics(User $instructor, Subject $subject, Collection $levels): void
    {
        $topics = $subject->topics()->active()->get();

        foreach ($topics as $topicOrder => $topic) {
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
                        'approved_by' => $this->approverId,
                    ],
                );

                $this->topicRows++;
            }
        }
    }

    private function syncCurriculumEligibility(User $instructor, Subject $subject): void
    {
        Curriculum::query()
            ->where('subject_id', $subject->id)
            ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
            ->with('educationSystemMappings')
            ->get()
            ->each(function (Curriculum $curriculum) use ($instructor): void {
                foreach ($curriculum->educationSystemMappings as $mapping) {
                    InstructorCurriculumEligibility::query()->updateOrCreate(
                        [
                            'teacher_id' => $instructor->id,
                            'education_system_id' => $mapping->education_system_id,
                            'curriculum_id' => $curriculum->id,
                        ],
                        [
                            'is_active' => true,
                            'notes' => 'Derived from the instructor\'s assigned subjects.',
                            'approved_at' => now(),
                            'approved_by' => $this->approverId,
                            'created_by' => $this->approverId,
                            'updated_by' => $this->approverId,
                        ],
                    );

                    $this->eligibilityRows++;
                }
            });
    }
}
