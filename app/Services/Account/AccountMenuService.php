<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Earnings\Support\InstructorPayoutEligibility;
use App\Enums\InstructorStatus;
use App\Enums\PortalAudience;
use App\Models\User;
use App\Services\FrontendPortalAudienceResolver;
use App\Settings\FeatureSettings;

/** The single owner of grouped authenticated-portal navigation. */
final class AccountMenuService
{
    public function __construct(
        private readonly FeatureSettings $features,
        private readonly InstructorPayoutEligibility $payoutEligibility,
        private readonly FrontendPortalAudienceResolver $audiences,
    ) {}

    /** @param array{notifications?: int, homework?: int} $badges */
    public function items(User $user, array $badges = []): array
    {
        $audience = $this->audiences->resolve($user);

        if ($audience === PortalAudience::AdminOrUnsupported) {
            return [];
        }

        $groups = $audience === PortalAudience::Student
            ? $this->studentGroups($badges)
            : $this->instructorGroups($user, $badges);

        return array_values(array_filter(array_map(function (array $group) use ($user): ?array {
            $items = array_values(array_filter(array_map(
                fn (array $item): ?array => $this->resolveItem($item, $user),
                $group['items'],
            )));

            return $items === [] ? null : ['label' => $group['label'], 'items' => $items];
        }, $groups)));
    }

    private function studentGroups(array $badges): array
    {
        return [
            ['label' => 'Overview', 'items' => [
                $this->item('Dashboard', 'dashboard', 'home', mobilePriority: 1),
            ]],
            ['label' => 'Learn', 'items' => [
                $this->item('Upcoming Classes', 'dashboard.upcoming-classes', 'calendar', mobilePriority: 2, mobileLabel: 'Classes'),
                $this->item('My Bookings', 'dashboard.my-bookings', 'clipboard'),
                $this->item('Homework', 'dashboard.homework', 'pencil', $badges['homework'] ?? 0, enabled: $this->features->homework_enabled, mobilePriority: 3),
                $this->item('Learning Plans', 'dashboard.learning-plans', 'clipboard'),
                $this->item('Learning Goals', 'dashboard.learning-goals', 'chart-bar'),
                $this->item('Progress', 'dashboard.progress', 'chart-bar'),
                $this->item('Attendance', 'dashboard.attendance', 'check-circle'),
                $this->item('Certificates', 'dashboard.certificates', 'badge'),
            ]],
            ['label' => 'Discover', 'items' => [
                $this->item('Find a Tutor', 'instructors.index', 'search'),
                $this->item('Favorite Instructors', 'dashboard.wishlist', 'heart'),
            ]],
            ['label' => 'Money', 'items' => [
                $this->item('Wallet', 'dashboard.wallet', 'credit-card', enabled: $this->features->wallet_enabled),
                $this->item('Payments', 'dashboard.payments', 'credit-card'),
                $this->item('Orders', 'dashboard.orders', 'bag'),
            ]],
            ['label' => 'Engage', 'items' => [
                $this->item('Refer a Friend', 'dashboard.refer-a-friend', 'heart', enabled: $this->features->referral_enabled),
                $this->item('Reviews', 'dashboard.reviews', 'star'),
                $this->item('Notifications', 'dashboard.notifications', 'bell', $badges['notifications'] ?? 0),
            ]],
            ['label' => 'Account', 'items' => [
                $this->item('My Profile', 'profile.show', 'user', permission: 'profile.view'),
                $this->item('Support Cases', 'dashboard.support-cases', 'lifebuoy'),
                $this->item('Messages', 'dashboard.messages', 'chat'),
                $this->item('FAQs', 'dashboard.faqs', 'help'),
            ]],
        ];
    }

    private function instructorGroups(User $user, array $badges): array
    {
        if (! $this->hasWorkspaceAccess($user)) {
            return [
                ['label' => 'Overview', 'items' => [
                    $this->item('Dashboard', 'dashboard', 'home', mobilePriority: 1),
                    $this->item('Application Status', 'dashboard.instructor.onboarding', 'clipboard', mobilePriority: 2),
                ]],
                ['label' => 'Account', 'items' => [
                    $this->item('Notifications', 'dashboard.notifications', 'bell', $badges['notifications'] ?? 0),
                    $this->item('My Profile', 'profile.show', 'user', permission: 'profile.view'),
                    $this->item('Support Cases', 'dashboard.support-cases', 'lifebuoy'),
                    $this->item('Messages', 'dashboard.messages', 'chat'),
                    $this->item('FAQs', 'dashboard.faqs', 'help'),
                ]],
            ];
        }

        $payouts = $this->payoutEligibility->isEligible($user);

        return [
            ['label' => 'Overview', 'items' => [$this->item('Dashboard', 'dashboard', 'home', mobilePriority: 1)]],
            ['label' => 'Teach', 'items' => [
                $this->item('Learning Plans', 'dashboard.instructor.learning-plans', 'clipboard'),
                $this->item('Availability', 'dashboard.instructor.availability', 'calendar', mobilePriority: 3),
                $this->item('Vacation Mode', 'dashboard.instructor.vacation', 'calendar'),
                $this->item('My Lessons', 'dashboard.instructor.lessons', 'clipboard', mobilePriority: 2, mobileLabel: 'Lessons'),
                $this->item('Homework', 'dashboard.instructor.homework', 'pencil', $badges['homework'] ?? 0, enabled: $this->features->homework_enabled),
                $this->item('Resource Library', 'dashboard.instructor.homework.resources', 'clipboard', enabled: $this->features->homework_enabled),
                $this->item('Students', 'dashboard.instructor.students', 'user'),
            ]],
            ['label' => 'Performance', 'items' => [
                $this->item('Reviews & Quality', 'dashboard.instructor.quality-insights', 'star'),
                $this->item('Analytics', 'dashboard.instructor.analytics', 'chart-bar'),
            ]],
            ['label' => 'Money', 'items' => [
                $this->item('Earnings', 'dashboard.instructor.earnings', 'credit-card'),
                $this->item('Settlements', 'dashboard.instructor.settlements', 'credit-card'),
                $this->item('Payout Methods', 'dashboard.instructor.payout-methods', 'credit-card', enabled: $payouts),
                $this->item('Withdrawals', 'dashboard.instructor.withdrawals', 'credit-card', enabled: $payouts),
            ]],
            ['label' => 'Account', 'items' => [
                $this->item('Notifications', 'dashboard.notifications', 'bell', $badges['notifications'] ?? 0),
                $this->item('My Profile', 'profile.show', 'user', permission: 'profile.view'),
                $this->item('Support Cases', 'dashboard.support-cases', 'lifebuoy'),
                $this->item('Messages', 'dashboard.messages', 'chat'),
                $this->item('FAQs', 'dashboard.faqs', 'help'),
            ]],
        ];
    }

    private function hasWorkspaceAccess(User $user): bool
    {
        return in_array($user->profile?->instructor_status, InstructorStatus::publiclyVisible(), true);
    }

    private function item(string $label, string $route, string $icon, int $badge = 0, bool $enabled = true, ?string $permission = null, ?int $mobilePriority = null, ?string $mobileLabel = null): array
    {
        return compact('label', 'route', 'icon', 'badge', 'enabled', 'permission', 'mobilePriority', 'mobileLabel');
    }

    private function resolveItem(array $item, User $user): ?array
    {
        if (! $item['enabled'] || ! \Route::has($item['route']) || ! $this->permitted($user, $item['permission'])) {
            return null;
        }

        return [
            'label' => $item['label'],
            'url' => route($item['route']),
            'route' => $item['route'],
            'icon' => $item['icon'],
            'badge' => $item['badge'] ?: null,
            'mobile_priority' => $item['mobilePriority'],
            'mobile_label' => $item['mobileLabel'],
        ];
    }

    private function permitted(User $user, ?string $permission): bool
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
