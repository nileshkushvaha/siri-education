<?php

declare(strict_types=1);

namespace Tests\Feature\Quality\Intelligence\Concerns;

use App\Models\LessonReview;
use App\Models\User;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait BuildsQualityInsightFixtures
{
    protected function enableQualityInsights(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        // The network-free P0 provider: the whole pipeline runs, no
        // external call is made, nothing real is spent.
        $settings->provider = 'fake';
        $settings->quality_insights_enabled = true;
        $settings->save();
    }

    /** @param list<string> $permissions */
    protected function admin(array $permissions = ['ViewAny:AiQualityInsight', 'View:AiQualityInsight', 'Generate:AiQualityInsight', 'Review:AiQualityInsight']): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin->fresh();
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        return $user->fresh();
    }

    protected function instructor(string $firstName = 'Priya', string $lastName = 'Nair'): User
    {
        $instructor = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $instructor->assignRole(Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']));

        return $instructor->fresh();
    }

    protected function student(string $firstName = 'Mira', string $lastName = 'Kowalski'): User
    {
        $student = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $student->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        return $student->fresh();
    }

    /**
     * A published public review attributed to this instructor/student
     * pair. The factory's own eligibility/lesson/booking chain is left
     * as-is — these tests are about what leaves the platform and what
     * comes back, not about review eligibility.
     *
     * @param  list<array{key: string, label: string}>  $tags
     */
    protected function publishedReview(User $instructor, User $student, int $rating, ?string $content, array $tags = []): LessonReview
    {
        return LessonReview::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'review_mode' => LessonReviewEligibilityMode::PublicReview,
            'status' => StudentReviewStatus::Published,
            'overall_rating' => $rating,
            'content' => $content,
            'tags' => $tags,
            'submitted_at' => now()->subDays(2),
        ]);
    }

    protected function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days);
    }
}
