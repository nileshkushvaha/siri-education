<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Models\AcademicLevel;
use App\Models\Language;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentProfilePreferenceService
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array{student_academic_level_id?: ?string, student_preferred_language_id?: ?int, preferred_subject_ids?: array<int, string>}  $data
     */
    public function update(User $student, array $data): User
    {
        return DB::transaction(function () use ($student, $data): User {
            $this->validateAcademicLevel($data['student_academic_level_id'] ?? null);
            $this->validateLanguage($data['student_preferred_language_id'] ?? null);

            $subjectIds = $this->validatedSubjectIds($data['preferred_subject_ids'] ?? []);
            $profile = $student->profile()->firstOrCreate(['user_id' => $student->id]);

            $profile->forceFill([
                'student_academic_level_id' => $data['student_academic_level_id'] ?? null,
                'student_preferred_language_id' => $data['student_preferred_language_id'] ?? null,
            ])->save();

            $student->preferredSubjects()->sync($subjectIds);

            $this->audit->logUser(
                $student,
                'student_profile',
                'student_preferences_updated',
                'Student preferences updated.',
                $profile,
                [
                    'student_academic_level_id' => $profile->student_academic_level_id,
                    'student_preferred_language_id' => $profile->student_preferred_language_id,
                    'preferred_subject_count' => count($subjectIds),
                ],
            );

            return $student->refresh()->load(['profile', 'preferredSubjects']);
        });
    }

    private function validateAcademicLevel(?string $academicLevelId): void
    {
        if ($academicLevelId === null || $academicLevelId === '') {
            return;
        }

        if (! AcademicLevel::availableForAssignment()->whereKey($academicLevelId)->exists()) {
            throw ValidationException::withMessages([
                'student_academic_level_id' => 'Please choose an active academic level.',
            ]);
        }
    }

    private function validateLanguage(null|int|string $languageId): void
    {
        if ($languageId === null || $languageId === '') {
            return;
        }

        if (! Language::active()->whereKey($languageId)->exists()) {
            throw ValidationException::withMessages([
                'student_preferred_language_id' => 'Please choose an active preferred language.',
            ]);
        }
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @return array<int, string>
     */
    private function validatedSubjectIds(array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_filter($subjectIds)));

        if ($subjectIds === []) {
            return [];
        }

        $validIds = Subject::availableForAssignment()
            ->whereIn('id', $subjectIds)
            ->pluck('id')
            ->all();

        if (count($validIds) !== count($subjectIds)) {
            throw ValidationException::withMessages([
                'preferred_subject_ids' => 'Please choose active subjects from the subject catalog.',
            ]);
        }

        return $validIds;
    }
}
