<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs profile-lifecycle events to the Activity Log.
 *
 * Two tiers:
 *  - Generic 'profile' / 'profile_updated' — fires for ANY user (student
 *    or instructor) when actual profile-content fields change. Limited to
 *    TRACKED_PROFILE_FIELDS so recalculation-only saves (profile_completion)
 *    or unrelated column touches don't create noise.
 *  - 'instructor' events — only for accounts holding the instructor role;
 *    generic profile updates on non-instructor accounts don't also fire these.
 */
class UserProfileObserver
{
    /** Fields whose change is worth a business-timeline entry; excludes system-recalculated/audit columns. */
    private const TRACKED_PROFILE_FIELDS = [
        'headline', 'short_bio', 'bio', 'phone', 'gender',
        'instructor_teaching_experience_summary', 'instructor_teaching_philosophy',
        'date_of_birth', 'address', 'city', 'country_id', 'state_id',
        'postal_code', 'website', 'facebook', 'twitter', 'linkedin',
        'github', 'instagram', 'youtube',
    ];

    public function __construct(
        private readonly AuditTrailService $auditTrail,
    ) {}

    public function updated(UserProfile $profile): void
    {
        $user = $profile->user;

        if (! $user) {
            return;
        }

        $changedTrackedFields = array_values(array_intersect(
            self::TRACKED_PROFILE_FIELDS,
            array_keys($profile->getDirty()),
        ));

        if ($changedTrackedFields !== []) {
            $this->log('profile', 'profile_updated', 'Profile updated', $user, [
                'changed_fields' => $changedTrackedFields,
            ]);
        }

        if (! $user->hasRole('instructor')) {
            return;
        }

        if ($profile->isDirty('instructor_status') && $profile->instructor_status !== null) {
            $event = 'profile_'.$profile->instructor_status->value;

            $this->log('instructor', $event, 'Instructor profile '.str_replace('_', ' ', $profile->instructor_status->value), $user, [
                'instructor_status' => $profile->instructor_status->value,
                'user_id' => $user->id,
            ]);
        }

        if ($profile->isDirty('profile_visibility')) {
            $this->log('instructor', 'visibility_changed', 'Instructor profile visibility changed', $user, [
                'profile_visibility' => $profile->profile_visibility,
                'user_id' => $user->id,
            ]);
        }

        if ($profile->isDirty('is_featured')) {
            $this->log('instructor', 'featured_changed', $profile->is_featured ? 'Instructor marked as featured' : 'Instructor removed from featured', $user, [
                'is_featured' => $profile->is_featured,
                'user_id' => $user->id,
            ]);
        }
    }

    private function log(string $logName, string $event, string $description, Model $subject, array $properties = []): void
    {
        /** @var User|null $causer */
        $causer = auth()->user();

        if ($causer instanceof User) {
            $this->auditTrail->logUser($causer, $logName, $event, $description, $subject, $properties);
        } else {
            $this->auditTrail->logSystem($logName, $event, $description, $subject, $properties);
        }
    }
}
