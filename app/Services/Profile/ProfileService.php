<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Actions\Profile\UpdateProfileAction;
use App\Actions\Profile\UploadAvatarAction;
use App\Enums\ThemePreference;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\AuditTrailService;
use App\Services\Auth\PasswordHistoryService;
use App\Services\Student\StudentProfilePreferenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

final class ProfileService
{
    public function __construct(
        private readonly UpdateProfileAction $updateProfile,
        private readonly UploadAvatarAction $uploadAvatar,
        private readonly PasswordHistoryService $historyService,
        private readonly ProfileCompletionService $completionService,
        private readonly AuditTrailService $auditTrail,
        private readonly StudentProfilePreferenceService $studentPreferences,
    ) {}

    public function update(User $user, array $data): User
    {
        $updated = $this->updateProfile->execute($user, $data);

        $this->auditTrail->logUser($user, 'profile', 'updated', 'Profile updated', $user, [
            'fields' => array_keys(array_filter($data)),
        ]);

        $this->completionService->recalculateAndStore($updated);

        if (
            array_key_exists('student_academic_level_id', $data)
            || array_key_exists('student_preferred_language_id', $data)
            || array_key_exists('preferred_subject_ids', $data)
        ) {
            $updated = $this->studentPreferences->update($updated, [
                'student_academic_level_id' => $data['student_academic_level_id'] ?? null,
                'student_preferred_language_id' => $data['student_preferred_language_id'] ?? null,
                'preferred_subject_ids' => $data['preferred_subject_ids'] ?? [],
            ]);

            $this->completionService->recalculateAndStore($updated);
        }

        return $updated;
    }

    /**
     * Persisted percentage (recalculated on every profile/avatar/cover/
     * visibility change by ProfileCompletionService) — a plain column read,
     * no computation on the request path.
     */
    public function completion(User $user): int
    {
        $user->loadMissing('profile');

        return $user->profile->profile_completion ?? 0;
    }

    public function changePassword(User $user, string $newPassword, string $ipAddress = '127.0.0.1'): void
    {
        $oldHash = $user->password;

        $this->historyService->assertNotReused($user, $newPassword);

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        $this->historyService->store($user, $oldHash);

        $this->auditTrail->logUser($user, 'profile', 'password_changed', 'Password changed', $user, [
            'ip' => $ipAddress,
        ]);

        $user->notify(new PasswordChangedNotification(
            ipAddress: $ipAddress,
            changedAt: Carbon::now()->toDateTimeString(),
        ));
    }

    public function uploadAvatar(User $user, UploadedFile $file): void
    {
        $this->uploadAvatar->execute($user, $file, 'avatar');

        $this->auditTrail->logUser($user, 'profile', 'avatar_changed', 'Profile photo updated', $user);

        $this->completionService->recalculateAndStore($user);
    }

    public function deleteAvatar(User $user): void
    {
        $this->uploadAvatar->delete($user, 'avatar');

        $this->auditTrail->logUser($user, 'profile', 'avatar_changed', 'Profile photo removed', $user);

        $this->completionService->recalculateAndStore($user);
    }

    public function uploadCover(User $user, UploadedFile $file): void
    {
        $this->uploadAvatar->execute($user, $file, 'cover');

        $this->auditTrail->logUser($user, 'profile', 'cover_changed', 'Cover photo updated', $user);

        $this->completionService->recalculateAndStore($user);
    }

    public function deleteCover(User $user): void
    {
        $this->uploadAvatar->delete($user, 'cover');

        $this->auditTrail->logUser($user, 'profile', 'cover_changed', 'Cover photo removed', $user);

        $this->completionService->recalculateAndStore($user);
    }

    public function updateVisibility(User $user, array $data): void
    {
        $user->profile->update($data);

        $this->auditTrail->logUser($user, 'profile', 'visibility_changed', 'Profile visibility updated', $user, [
            'fields' => array_keys($data),
        ]);
    }

    /**
     * Frontend-portal colour theme. Stored on the profile row; resolved
     * for rendering by App\Services\ThemeResolver (light when unset).
     */
    public function updateThemePreference(User $user, ThemePreference $theme): void
    {
        $previous = $user->profile->theme_preference;

        if ($previous === $theme) {
            return;
        }

        $user->profile->update(['theme_preference' => $theme]);

        $this->auditTrail->logUser($user, 'profile', 'theme_changed', 'Portal theme changed', $user, [
            'from' => $previous?->value,
            'to' => $theme->value,
        ]);
    }
}
