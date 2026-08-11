<?php

declare(strict_types=1);

namespace App\Curriculum\Services;

use App\Curriculum\Exceptions\InstructorAcademicEligibilityException;
use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative writer of Instructor Academic Eligibility
 * (Instructor + EducationSystem + Curriculum), mirroring
 * EducationSystemService's role for its mapping tables. Filament and
 * any future booking-adjacent caller must go through this service —
 * eligibility rows are never created/mutated directly, so validation,
 * duplicate prevention, and audit logging cannot be bypassed.
 *
 * Phase 2 architectural decisions (see docs/architecture/domain-registry.md
 * and the task's own spec for the full reasoning):
 *
 * - Eligibility anchors to Curriculum IDENTITY, never CurriculumVersion.
 * - education_system_id is always explicit and never inferred from the
 *   Curriculum's own EducationSystem mappings, because a system-neutral
 *   Curriculum may be independently approved under more than one system.
 * - Existing instructor Subject/Level capability (TeacherSubject —
 *   confirmed authoritative over the unstructured
 *   UserProfile::instructor_academic_level_ids, which is display/
 *   marketplace-filter-only and not per-subject) is validated on every
 *   assign()/reactivate() call — this service never lets an instructor
 *   become eligible for a Curriculum whose Subject+Level they are not
 *   already recorded as teaching.
 * - Level/grade rule: a TeacherSubject row's grade_from/grade_to must
 *   fully cover the Curriculum's AcademicLevel min_grade/max_grade
 *   range (both TeacherSubject bounds null = unbounded, per its own
 *   docblock). When the AcademicLevel itself carries no numeric grade
 *   range (min_grade and max_grade both null — e.g. an Undergraduate-
 *   style level), the numeric check is skipped and only the Subject
 *   match is required — this is the safest existing booking-compatible
 *   rule available; see the completion report for the grade/level
 *   inconsistency this surfaced between TeacherSubject and
 *   UserProfile::instructor_academic_level_ids.
 * - A Curriculum lacking a currently-Published CurriculumVersion may
 *   still receive administrative eligibility here (the instructor's
 *   qualification is real even while content is being revised); the
 *   runtime "is this instructor bookable right now" question is
 *   answered separately by InstructorAcademicEligibilityResolver,
 *   which only ever receives an AcademicContextData that
 *   AcademicContextResolver has already refused to build without a
 *   Published version.
 */
final class InstructorAcademicEligibilityService
{
    private const LOG_NAME = 'instructor_academic_eligibility';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Create (or fail loudly on duplicate) an active eligibility row.
     * Assignment IS approval — there is no separate submission workflow,
     * mirroring how InstructorSubjectTopic's approval fields are simply
     * set by the admin performing the action.
     */
    public function assign(User $admin, User $instructor, EducationSystem $system, Curriculum $curriculum, ?string $notes = null): InstructorCurriculumEligibility
    {
        $this->assertCan($admin, 'create', InstructorCurriculumEligibility::class);
        $this->assertInstructor($instructor);
        $this->validateConfiguration($instructor, $system, $curriculum);

        try {
            return DB::transaction(function () use ($admin, $instructor, $system, $curriculum, $notes): InstructorCurriculumEligibility {
                $existing = InstructorCurriculumEligibility::query()
                    ->where('teacher_id', $instructor->id)
                    ->where('education_system_id', $system->id)
                    ->where('curriculum_id', $curriculum->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new InstructorAcademicEligibilityException(sprintf(
                        'Instructor "%s" already has an eligibility record for curriculum "%s" under education system "%s". Use reactivate() instead of creating a duplicate.',
                        $instructor->name,
                        $curriculum->name,
                        $system->name,
                    ));
                }

                $eligibility = InstructorCurriculumEligibility::query()->create([
                    'teacher_id' => $instructor->id,
                    'education_system_id' => $system->id,
                    'curriculum_id' => $curriculum->id,
                    'is_active' => true,
                    'notes' => $notes,
                    'approved_at' => now(),
                    'approved_by' => $admin->id,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]);

                $this->audit->logUser($admin, self::LOG_NAME, 'instructor_curriculum_eligibility_added', sprintf(
                    'Instructor "%s" approved to teach curriculum "%s" under education system "%s".',
                    $instructor->name,
                    $curriculum->name,
                    $system->name,
                ), $eligibility, $this->auditMetadata($instructor, $system, $curriculum));

                return $eligibility->refresh();
            });
        } catch (QueryException $e) {
            // Concurrency fallback: two admins racing past the exists()
            // check above both reach create() — the DB unique constraint
            // is the final authority, never exists()-then-create() alone.
            if ($this->isDuplicateKeyViolation($e)) {
                throw new InstructorAcademicEligibilityException(sprintf(
                    'Instructor "%s" already has an eligibility record for curriculum "%s" under education system "%s".',
                    $instructor->name,
                    $curriculum->name,
                    $system->name,
                ));
            }

            throw $e;
        }
    }

    /** Switches an eligibility row off. Never deletes it — history remains auditable. */
    public function deactivate(User $admin, InstructorCurriculumEligibility $eligibility, ?string $reason = null): InstructorCurriculumEligibility
    {
        $this->assertCan($admin, 'update', $eligibility);

        return DB::transaction(function () use ($admin, $eligibility, $reason): InstructorCurriculumEligibility {
            $eligibility->fill(['is_active' => false, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'instructor_curriculum_eligibility_deactivated', sprintf(
                'Instructor "%s" eligibility for curriculum "%s" under education system "%s" deactivated.',
                $eligibility->teacher?->name ?? (string) $eligibility->teacher_id,
                $eligibility->curriculum?->name ?? (string) $eligibility->curriculum_id,
                $eligibility->educationSystem?->name ?? (string) $eligibility->education_system_id,
            ), $eligibility, array_filter([
                'eligibility_id' => $eligibility->id,
                'reason' => $reason,
            ]));

            return $eligibility->refresh();
        });
    }

    /** Re-validates and switches an eligibility row back on — conditions may have changed since deactivation. */
    public function reactivate(User $admin, InstructorCurriculumEligibility $eligibility): InstructorCurriculumEligibility
    {
        $this->assertCan($admin, 'update', $eligibility);

        $instructor = $eligibility->teacher;
        $system = $eligibility->educationSystem;
        $curriculum = $eligibility->curriculum;

        if ($instructor === null || $system === null || $curriculum === null) {
            throw new InstructorAcademicEligibilityException('Eligibility record is missing a required relation and cannot be reactivated.');
        }

        $this->validateConfiguration($instructor, $system, $curriculum);

        return DB::transaction(function () use ($admin, $eligibility, $instructor, $system, $curriculum): InstructorCurriculumEligibility {
            $eligibility->fill(['is_active' => true, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'instructor_curriculum_eligibility_reactivated', sprintf(
                'Instructor "%s" eligibility for curriculum "%s" under education system "%s" reactivated.',
                $instructor->name,
                $curriculum->name,
                $system->name,
            ), $eligibility, $this->auditMetadata($instructor, $system, $curriculum));

            return $eligibility->refresh();
        });
    }

    /**
     * Validates that Instructor/System/Curriculum form a legal
     * eligibility configuration, without persisting anything. Exposed
     * separately so Filament form validation and assign()/reactivate()
     * share exactly one rule set.
     */
    public function validateConfiguration(User $instructor, EducationSystem $system, Curriculum $curriculum): void
    {
        if ($system->status !== AcademicStatus::Active) {
            throw new InstructorAcademicEligibilityException(sprintf('Education system "%s" is not active.', $system->name));
        }

        $subject = $curriculum->subject;
        $level = $curriculum->academicLevel;

        if ($subject === null || $subject->status !== AcademicStatus::Active) {
            throw new InstructorAcademicEligibilityException(sprintf('Curriculum "%s" has no active subject.', $curriculum->name));
        }

        if ($level === null || $level->status !== AcademicStatus::Active) {
            throw new InstructorAcademicEligibilityException(sprintf('Curriculum "%s" has no active academic level.', $curriculum->name));
        }

        if (! $curriculum->appliesToEducationSystem($system)) {
            throw new InstructorAcademicEligibilityException(sprintf(
                'Curriculum "%s" is not applicable to education system "%s".',
                $curriculum->name,
                $system->name,
            ));
        }

        if (! $this->instructorTeachesSubjectAndLevel($instructor, $subject->id, $subject->name, $level)) {
            throw new InstructorAcademicEligibilityException(sprintf(
                'Instructor "%s" is not already recorded as teaching subject "%s" at the grade range required for academic level "%s".',
                $instructor->name,
                $subject->name,
                $level->name,
            ));
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * The current authoritative Subject+Level capability check: reuses
     * TeacherSubject (grade_from/grade_to), matched to the Curriculum's
     * Subject via subject_id when linked, falling back to the same
     * case-insensitive exact-name match the Subject/TeacherSubject
     * backfill uses (see docs/architecture/subject-teacher-subject-
     * reconciliation.md) — never UserProfile::instructor_academic_level_ids,
     * which is an unstructured, non-per-subject, display/marketplace-
     * filter field disconnected from actual booking eligibility.
     */
    private function instructorTeachesSubjectAndLevel(User $instructor, string $subjectId, string $subjectName, AcademicLevel $level): bool
    {
        $matches = TeacherSubject::query()
            ->where('teacher_id', $instructor->id)
            ->where(fn ($q) => $q
                ->where('subject_id', $subjectId)
                ->orWhereRaw('LOWER(subject) = ?', [mb_strtolower($subjectName)]))
            ->get();

        if ($matches->isEmpty()) {
            return false;
        }

        if ($level->min_grade === null && $level->max_grade === null) {
            // No numeric grade range on this level (e.g. Undergraduate) —
            // nothing to compare a grade range against, so Subject match
            // alone is the authoritative rule for this level.
            return true;
        }

        $levelMin = $level->min_grade ?? 1;
        $levelMax = $level->max_grade ?? 12;

        return $matches->contains(function (TeacherSubject $teacherSubject) use ($levelMin, $levelMax): bool {
            $from = $teacherSubject->grade_from ?? 1;
            $to = $teacherSubject->grade_to ?? 12;

            return $from <= $levelMin && $to >= $levelMax;
        });
    }

    private function assertInstructor(User $instructor): void
    {
        if (! $instructor->hasRole('instructor')) {
            throw new InstructorAcademicEligibilityException(sprintf('User "%s" is not an instructor.', $instructor->name));
        }
    }

    /** @return array<string, mixed> */
    private function auditMetadata(User $instructor, EducationSystem $system, Curriculum $curriculum): array
    {
        return [
            'instructor_id' => $instructor->id,
            'education_system_id' => $system->id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $curriculum->subject_id,
            'academic_level_id' => $curriculum->academic_level_id,
        ];
    }

    private function isDuplicateKeyViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }

    private function assertCan(User $admin, string $ability, mixed $subject): void
    {
        if (! $admin->can($ability, $subject)) {
            throw new AuthorizationException;
        }
    }
}
