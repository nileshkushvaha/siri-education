<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Models\StudentLearningGoal;
use App\Models\User;

/**
 * Calculates and persists profile-completion percentage. Each section
 * carries a weight (points out of 100) and a score callable returning
 * 0.0-1.0 (partial credit within a bucket, e.g. 3 of 5 basic fields
 * filled = 0.6). calculate() sums weight x score. Adding a new weighted
 * section is a one-line array entry — never a hardcoded percentage in a
 * controller or view.
 *
 * The checklist is ROLE-AWARE. Work experience, education and social
 * links describe an instructor; a student was being told their profile
 * was 11% complete and "still missing: Work experience" for sections
 * they can never fill. Students are scored on what the platform actually
 * uses for them: identity, contact verification, academic profile and
 * learning goals. Instructors (and any other frontend role) keep the
 * professional checklist.
 */
final class ProfileCompletionService
{
    /**
     * @var array<int, string>
     */
    private const SOCIAL_FIELDS = ['website', 'facebook', 'twitter', 'linkedin', 'github', 'instagram', 'youtube'];

    /**
     * @return array<string, array{weight: int, score: callable(User): float}>
     */
    private function sections(User $user): array
    {
        return $this->isStudentOnly($user) ? $this->studentSections() : $this->professionalSections();
    }

    /** A student who is not also an instructor. Role checks go through Spatie directly, per the portal rules. */
    private function isStudentOnly(User $user): bool
    {
        return $user->hasRole('student') && ! $user->hasRole('instructor');
    }

    /**
     * @return array<string, array{weight: int, score: callable(User): float}>
     */
    private function studentSections(): array
    {
        return [
            'basic_profile' => [
                'weight' => 20,
                'score' => fn (User $user): float => $this->studentBasicProfileScore($user),
            ],
            'avatar' => [
                'weight' => 15,
                'score' => fn (User $user): float => $user->profile->hasMedia('avatar') ? 1.0 : 0.0,
            ],
            'phone_verified' => [
                'weight' => 15,
                'score' => fn (User $user): float => $user->profile->phone_verified_at !== null ? 1.0 : 0.0,
            ],
            'academic_profile' => [
                'weight' => 30,
                'score' => fn (User $user): float => $this->academicProfileScore($user),
            ],
            'learning_goals' => [
                'weight' => 20,
                'score' => fn (User $user): float => StudentLearningGoal::query()->where('user_id', $user->id)->activeForDashboard()->exists() ? 1.0 : 0.0,
            ],
        ];
    }

    private function studentBasicProfileScore(User $user): float
    {
        $checks = [
            filled($user->first_name),
            filled($user->last_name),
            (bool) $user->email_verified_at,
            filled($user->profile->phone),
            filled($user->profile->country_id),
            filled($user->profile->timezone),
            $user->profile->date_of_birth !== null,
        ];

        return count(array_filter($checks)) / count($checks);
    }

    /** Level, preferred language and at least one preferred subject — what lesson matching keys off. */
    private function academicProfileScore(User $user): float
    {
        $checks = [
            filled($user->profile->student_academic_level_id),
            filled($user->profile->student_preferred_language_id),
            $user->preferredSubjects()->exists(),
        ];

        return count(array_filter($checks)) / count($checks);
    }

    /**
     * @return array<string, array{weight: int, score: callable(User): float}>
     */
    private function professionalSections(): array
    {
        return [
            'basic_profile' => [
                'weight' => 20,
                'score' => fn (User $user): float => $this->basicProfileScore($user),
            ],
            'avatar' => [
                'weight' => 10,
                'score' => fn (User $user): float => $user->profile->hasMedia('avatar') ? 1.0 : 0.0,
            ],
            'bio' => [
                'weight' => 10,
                'score' => fn (User $user): float => filled($user->profile->bio) ? 1.0 : 0.0,
            ],
            'experience' => [
                'weight' => 30,
                'score' => fn (User $user): float => $user->experiences()->active()->exists() ? 1.0 : 0.0,
            ],
            'education' => [
                'weight' => 20,
                'score' => fn (User $user): float => $user->educations()->active()->exists() ? 1.0 : 0.0,
            ],
            'social_links' => [
                'weight' => 10,
                'score' => fn (User $user): float => $this->socialLinksScore($user),
            ],
        ];
    }

    private function basicProfileScore(User $user): float
    {
        $checks = [
            filled($user->first_name),
            filled($user->last_name),
            (bool) $user->email_verified_at,
            filled($user->profile->headline),
            filled($user->profile->phone),
            filled($user->profile->address),
            filled($user->profile->country_id),
        ];

        return count(array_filter($checks)) / count($checks);
    }

    private function socialLinksScore(User $user): float
    {
        $filled = collect(self::SOCIAL_FIELDS)
            ->filter(fn (string $field): bool => filled($user->profile->{$field}))
            ->count();

        return $filled / count(self::SOCIAL_FIELDS);
    }

    /**
     * @return array<string, array{weight: int, score: float, earned: float}>
     */
    public function breakdown(User $user): array
    {
        $user->loadMissing('profile');

        return collect($this->sections($user))
            ->map(function (array $section) use ($user): array {
                $score = ($section['score'])($user);

                return [
                    'weight' => $section['weight'],
                    'score' => $score,
                    'earned' => round($section['weight'] * $score, 1),
                ];
            })
            ->all();
    }

    public function calculate(User $user): int
    {
        $earned = array_sum(array_column($this->breakdown($user), 'earned'));

        return (int) round($earned);
    }

    public function recalculateAndStore(User $user): int
    {
        $percentage = $this->calculate($user);

        // profile_completion is deliberately absent from UserProfile's
        // $fillable — it must never be settable via mass-assignment from a
        // form (frontend or Filament). forceFill() is the one sanctioned
        // write path, used only here.
        $user->profile->forceFill(['profile_completion' => $percentage])->saveQuietly();

        return $percentage;
    }
}
