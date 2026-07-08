<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\User;
use App\Settings\FeatureSettings;

/**
 * Builds the permission-driven Account Portal sidebar menu.
 *
 * PortalResolver::frontendMenu() is off-limits to modify and is not
 * permission-aware, so this service supersedes it for sidebar rendering.
 * PortalResolver still owns portal (WHERE) resolution; this service only
 * decides which already-portal-scoped menu items a user may see (WHAT).
 *
 * Each item: label, route (named route for the URL + active-state match),
 * icon, audience (student|instructor|shared), permission (null = always
 * visible once on the portal), badge (nullable), children (nested items,
 * same shape — one level supported).
 */
final class AccountMenuService
{
    public function __construct(
        private readonly HomeworkServiceInterface $homework,
        private readonly FeatureSettings $features,
    ) {}

    /**
     * @return array<int, array{label: string, url: string, route: string, icon: string, permission: ?string, badge: mixed, children: array}>
     */
    public function items(User $user): array
    {
        return array_values(array_filter(array_map(
            fn (array $item): ?array => $this->resolve($item, $user),
            $this->definitions(),
        )));
    }

    /**
     * @return array<int, array{label: string, route: string, icon: string, permission: ?string, badge?: mixed, children?: array}>
     */
    private function definitions(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'route' => 'dashboard',
                'icon' => 'home',
                'audience' => 'shared',
                'permission' => null,
            ],
            [
                'label' => 'Upcoming Classes',
                'route' => 'dashboard.upcoming-classes',
                'icon' => 'calendar',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'My Bookings',
                'route' => 'dashboard.my-bookings',
                'icon' => 'clipboard',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Payments',
                'route' => 'dashboard.payments',
                'icon' => 'credit-card',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Wallet',
                'route' => 'dashboard.wallet',
                'icon' => 'credit-card',
                'audience' => 'student',
                'permission' => null,
                'enabled' => fn (): bool => $this->features->wallet_enabled,
            ],
            [
                'label' => 'Homework',
                'route' => 'dashboard.homework',
                'icon' => 'pencil',
                'audience' => 'student',
                'permission' => null,
                'badge' => fn (User $user): mixed => ($this->homework->statsForStudent($user->id)->pending ?? 0) ?: null,
            ],
            [
                'label' => 'Attendance',
                'route' => 'dashboard.attendance',
                'icon' => 'check-circle',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Progress',
                'route' => 'dashboard.progress',
                'icon' => 'chart-bar',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'My Profile',
                'route' => 'profile.show',
                'icon' => 'user',
                'audience' => 'shared',
                'permission' => 'profile.view',
            ],
            [
                'label' => 'Learning Goals',
                'route' => 'dashboard.learning-goals',
                'icon' => 'chart-bar',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Learning Plans',
                'route' => 'dashboard.learning-plans',
                'icon' => 'clipboard',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Learning Plans',
                'route' => 'dashboard.instructor.learning-plans',
                'icon' => 'clipboard',
                'audience' => 'instructor',
                'permission' => null,
            ],
            [
                'label' => 'Availability',
                'route' => 'dashboard.instructor.availability',
                'icon' => 'calendar',
                'audience' => 'instructor',
                'permission' => null,
            ],
            [
                'label' => 'Certificates',
                'route' => 'dashboard.certificates',
                'icon' => 'badge',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Orders',
                'route' => 'dashboard.orders',
                'icon' => 'bag',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Favorite Instructors',
                'route' => 'dashboard.wishlist',
                'icon' => 'heart',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Reviews',
                'route' => 'dashboard.reviews',
                'icon' => 'star',
                'audience' => 'student',
                'permission' => null,
            ],
            [
                'label' => 'Notifications',
                'route' => 'dashboard.notifications',
                'icon' => 'bell',
                'audience' => 'shared',
                'permission' => null,
                'badge' => fn (User $user): mixed => $user->unreadNotifications()->count() ?: null,
            ],
            [
                'label' => 'FAQs',
                'route' => 'dashboard.faqs',
                'icon' => 'help',
                'audience' => 'shared',
                'permission' => null,
            ],
        ];
    }

    private function resolve(array $item, User $user): ?array
    {
        if (! $this->isAudienceVisible($user, $item['audience'] ?? 'shared')) {
            return null;
        }

        if (! $this->isVisible($user, $item['permission'] ?? null)) {
            return null;
        }

        if (isset($item['enabled']) && ! ($item['enabled'])()) {
            return null;
        }

        $children = array_values(array_filter(array_map(
            fn (array $child): ?array => $this->resolve($child, $user),
            $item['children'] ?? [],
        )));

        return [
            'label' => $item['label'],
            'url' => route($item['route'], $item['params'] ?? []),
            'route' => $item['route'],
            'icon' => $item['icon'] ?? 'default',
            'audience' => $item['audience'] ?? 'shared',
            'badge' => is_callable($item['badge'] ?? null) ? ($item['badge'])($user) : ($item['badge'] ?? null),
            'children' => $children,
        ];
    }

    private function isAudienceVisible(User $user, string $audience): bool
    {
        return match ($audience) {
            'student' => $user->hasRole('student') || ! $user->hasRole('instructor'),
            'instructor' => $user->hasRole('instructor') && ! $user->hasRole('student'),
            default => true,
        };
    }

    private function isVisible(User $user, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        try {
            return $user->can($permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
