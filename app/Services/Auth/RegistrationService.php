<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Actions\Auth\RegisterUserAction;
use App\Enums\StudentStatus;
use App\Events\Auth\UserRegistered;
use App\Exceptions\Auth\RegistrationException;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Services\Student\StudentLifecycleService;
use App\Settings\PasswordPolicySettings;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

final class RegistrationService
{
    public function __construct(
        private readonly RegisterUserAction $registerAction,
        private readonly RegistrationSettings $regSettings,
        private readonly PasswordPolicySettings $policySettings,
        private readonly ReferralAttributionServiceInterface $referralAttribution,
        private readonly RegistrationCaptchaService $captcha,
        private readonly StudentLifecycleService $studentLifecycle,
    ) {}

    /**
     * Register a new user, applying every RegistrationSettings rule.
     *
     * @throws RegistrationException when the configured default role does not exist
     */
    public function register(array $data, string $ipAddress, string $userAgent): RegistrationResult
    {
        $data['accepted_ip'] = $ipAddress;
        $data['accepted_user_agent'] = $userAgent;

        // 1. Validate default role exists — fail fast before creating the user
        $role = $this->resolveDefaultRole();

        $requireApproval = $this->regSettings->require_admin_approval;
        $autoVerify = $this->regSettings->auto_verify_email;

        // 2. Determine initial status
        //    INACTIVE  → pending admin approval (cannot log in, no verification email yet)
        //    PENDING   → normal flow (logged in temporarily so signed verification URL works)
        $status = $requireApproval
            ? User::STATUS_INACTIVE
            : User::STATUS_PENDING;

        // 3. Apply force-change-on-first-login from password policy
        $mustChangePassword = $this->policySettings->force_change_on_first_login;

        // 4. Persist the user
        $user = $this->registerAction->execute($data, $status, $mustChangePassword);

        // 5. Auto-verify email — only when approval is NOT required
        //    (Approval-flow users must be activated by admin first.)
        if ($autoVerify && ! $requireApproval) {
            $user->forceFill([
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
            ])->saveQuietly();
        }

        // 6. Assign default role
        if ($role) {
            $user->assignRole($role);

            // Student lifecycle starts here — separate from User::status
            // (account/login eligibility) and InstructorStatus (which stays
            // null until a user actually applies to teach).
            if ($role->name === 'student') {
                $user->profile?->update(['student_status' => StudentStatus::Registered]);

                // Auto-verified registrations (step 5) never enter a
                // verification code, so this is the only place that
                // trigger fires for them — same idempotent
                // Registered-only entry point the code screen uses.
                if ($autoVerify && ! $requireApproval) {
                    $this->studentLifecycle->activateFromVerification($user);
                }

                // Referral attribution — students only, registration-time
                // only, strictly best-effort: the service swallows every
                // invalid-code condition and never blocks registration.
                $this->referralAttribution->attributeFromRegistration(
                    $user,
                    $data['referral_code'] ?? null,
                    $data['referral_code_source'] ?? null,
                );
            }
        }

        // 7. Dispatch event — listener handles all notifications + activity logging
        UserRegistered::dispatch($user, $ipAddress, $userAgent);
        $this->captcha->clear();

        return new RegistrationResult(
            user: $user,
            requiresApproval: $requireApproval,
            autoVerified: $autoVerify && ! $requireApproval,
        );
    }

    /**
     * Resolve and validate the configured default role.
     * Returns null if no role is configured.
     *
     * @throws RegistrationException when a role name is configured but the role does not exist
     */
    private function resolveDefaultRole(): ?Role
    {
        $roleName = $this->regSettings->default_role;

        if (blank($roleName)) {
            return null;
        }

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            Log::error('Registration blocked: configured default role does not exist.', [
                'role' => $roleName,
            ]);

            activity('auth')
                ->event('registration_blocked')
                ->withProperties(['reason' => 'invalid_default_role', 'role' => $roleName])
                ->log("Registration blocked: default role '{$roleName}' not found");

            throw new RegistrationException(
                'Registration is temporarily unavailable. Please contact support.',
            );
        }

        return $role;
    }
}
