<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\PortalAudience;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\User;
use App\Services\FrontendPortalAudienceResolver;
use App\Settings\FeatureSettings;
use Throwable;

final class PortalBadgeService
{
    /** @var array<int, array{notifications: int, homework: int}> */
    private array $resolved = [];

    public function __construct(
        private readonly HomeworkServiceInterface $homework,
        private readonly FeatureSettings $features,
        private readonly FrontendPortalAudienceResolver $audiences,
    ) {}

    /** @return array{notifications: int, homework: int} */
    public function for(User $user): array
    {
        return $this->resolved[$user->id] ??= [
            'notifications' => $this->safely(fn (): int => $user->unreadNotifications()->count()),
            'homework' => $this->audiences->resolve($user) === PortalAudience::Student && $this->features->homework_enabled
                ? $this->safely(fn (): int => (int) ($this->homework->statsForStudent($user->id)->pending ?? 0))
                : 0,
        ];
    }

    private function safely(callable $read): int
    {
        try {
            return max(0, (int) $read());
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }
}
