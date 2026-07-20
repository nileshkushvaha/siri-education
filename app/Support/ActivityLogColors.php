<?php

declare(strict_types=1);

namespace App\Support;

final class ActivityLogColors
{
    public static function forEvent(?string $event): string
    {
        return match ($event) {
            'created', 'registered' => 'success',
            'updated', 'roles_updated', 'profile_updated',
            'password_changed', 'photo_updated', 'role_updated',
            '2fa_enabled', '2fa_disabled', 'account_unlocked' => 'warning',
            'deleted', 'login_failed' => 'danger',
            'login', 'logout', 'password_reset', 'auto_published',
            'manually_ran', 'webhook_received', 'role_created',
            'photo_removed', 'password_reset_requested', 'replicated' => 'info',
            default => 'gray',
        };
    }
}
