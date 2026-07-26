<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\User;

/**
 * Immutable summary of the current user for the Account Portal's shared
 * profile header component. Built by AccountPortalComposer so every
 * authenticated frontend page renders the identical header data.
 */
final readonly class AccountProfileSummaryData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $avatarUrl,
        public ?string $coverUrl,
        public ?string $avatarThumbUrl,
        public ?string $coverDisplayUrl,
        public string $initial,
        public bool $emailVerified,
        public ?string $lastLoginHuman,
        public string $memberSinceHuman,
        public int $profileCompletion,
        public bool $online = true,
    ) {}

    public static function fromUser(User $user, int $profileCompletion): self
    {
        $displayName = $user->first_name
            ? trim($user->first_name.' '.($user->last_name ?? ''))
            : $user->name;

        return new self(
            name: $displayName,
            email: $user->email,
            avatarUrl: $user->profile->avatar_url,
            coverUrl: $user->profile->cover_url,
            avatarThumbUrl: $user->profile->avatar_thumb_url,
            coverDisplayUrl: $user->profile->cover_display_url,
            initial: strtoupper(substr($user->first_name ?? $user->name, 0, 1)),
            emailVerified: (bool) $user->email_verified_at,
            lastLoginHuman: $user->last_login_at?->diffForHumans(),
            memberSinceHuman: $user->created_at->format('M Y'),
            profileCompletion: $profileCompletion,
        );
    }
}
