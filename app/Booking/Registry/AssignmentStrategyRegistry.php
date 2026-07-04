<?php

declare(strict_types=1);

namespace App\Booking\Registry;

use App\Booking\Contracts\AssignmentStrategyInterface;
use App\Booking\Exceptions\BookingException;

/** Registered assignment algorithms, keyed for BookingSettings. */
final class AssignmentStrategyRegistry
{
    /** @var array<string, AssignmentStrategyInterface> */
    private array $strategies = [];

    public function register(AssignmentStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->key()] = $strategy;
    }

    public function get(string $key): AssignmentStrategyInterface
    {
        return $this->strategies[$key]
            ?? throw new BookingException(sprintf('Assignment strategy "%s" is not registered.', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    /** @return array<string, AssignmentStrategyInterface> */
    public function all(): array
    {
        return $this->strategies;
    }
}
