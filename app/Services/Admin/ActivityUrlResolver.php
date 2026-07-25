<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Filament\Pages\Security\AccountProtectionPage;
use App\Filament\Pages\Security\AuthenticationPage;
use App\Filament\Pages\Security\LoginSecurityPage;
use App\Filament\Pages\Security\PasswordPolicyPage;
use App\Filament\Pages\Security\RegistrationPage;
use App\Filament\Pages\Security\SessionPage;
use App\Filament\Resources\ActivityLog\ActivityLogResource;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Activity;
use App\Models\Conversation;
use App\Models\Message;
use Throwable;

/**
 * Maps an Activity Log record to the most relevant Filament admin URL.
 * URL resolution is deliberately isolated here so the mapper and service
 * stay free of routing knowledge.
 */
final class ActivityUrlResolver
{
    public function resolve(Activity $activity): ?string
    {
        try {
            return match ($activity->log_name) {
                'users' => $this->resolveUserUrl($activity),
                'roles' => $this->resolveRoleUrl($activity),
                'security' => $this->resolveSecurityUrl($activity),
                'auth' => $this->resolveAuthUrl($activity),
                'support_cases' => $this->resolveSupportCaseUrl($activity),
                'messaging' => $this->resolveMessagingUrl($activity),
                default => ActivityLogResource::getUrl('index'),
            };
        } catch (Throwable) {
            return null;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function resolveUserUrl(Activity $activity): string
    {
        if ($activity->event === 'deleted' || ! $activity->subject_id) {
            return UserResource::getUrl('index');
        }

        return UserResource::getUrl('edit', ['record' => $activity->subject_id]);
    }

    private function resolveRoleUrl(Activity $activity): string
    {
        if ($activity->event === 'deleted' || ! $activity->subject_id) {
            return RoleResource::getUrl('index');
        }

        return RoleResource::getUrl('edit', ['record' => $activity->subject_id]);
    }

    private function resolveSecurityUrl(Activity $activity): string
    {
        if ($activity->event === 'settings_updated') {
            $page = data_get($activity->properties, 'page', '');

            return match ($page) {
                'authentication' => AuthenticationPage::getUrl(),
                'password_policy' => PasswordPolicyPage::getUrl(),
                'login_security' => LoginSecurityPage::getUrl(),
                'session' => SessionPage::getUrl(),
                'registration' => RegistrationPage::getUrl(),
                'account_protection' => AccountProtectionPage::getUrl(),
                default => ActivityLogResource::getUrl('index'),
            };
        }

        // 2FA disable: the subject is the User
        if ($activity->subject_id) {
            return UserResource::getUrl('edit', ['record' => $activity->subject_id]);
        }

        return ActivityLogResource::getUrl('index');
    }

    private function resolveAuthUrl(Activity $activity): string
    {
        // Account lock/unlock actions: subject is the User
        $lockEvents = ['account_locked', 'manual_lock', 'manual_unlock', 'self_service_unlock'];

        if (in_array($activity->event, $lockEvents, true) && $activity->subject_id) {
            return UserResource::getUrl('edit', ['record' => $activity->subject_id]);
        }

        return ActivityLogResource::getUrl('index');
    }

    private function resolveSupportCaseUrl(Activity $activity): string
    {
        if ($activity->subject_id) {
            return SupportCaseResource::getUrl('view', ['record' => $activity->subject_id]);
        }

        return SupportCaseResource::getUrl('index');
    }

    private function resolveMessagingUrl(Activity $activity): string
    {
        if ($activity->subject_type === Message::class && $activity->subject_id) {
            $message = Message::query()->find($activity->subject_id);

            if ($message !== null) {
                return ConversationResource::getUrl('view', ['record' => $message->conversation_id]);
            }
        }

        if ($activity->subject_type === Conversation::class && $activity->subject_id) {
            return ConversationResource::getUrl('view', ['record' => $activity->subject_id]);
        }

        return ConversationResource::getUrl('index');
    }
}
