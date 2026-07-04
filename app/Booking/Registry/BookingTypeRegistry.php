<?php

declare(strict_types=1);

namespace App\Booking\Registry;

use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\Exceptions\UnknownBookingTypeException;

/**
 * Single source of truth for available booking types.
 * Types register in BookingServiceProvider — mirrors LinkTypeRegistry.
 */
final class BookingTypeRegistry
{
    /** @var array<string, BookingTypeInterface> */
    private array $types = [];

    public function register(BookingTypeInterface $type): void
    {
        $this->types[$type->key()] = $type;
    }

    /** @throws UnknownBookingTypeException */
    public function get(string $key): BookingTypeInterface
    {
        return $this->types[$key] ?? throw UnknownBookingTypeException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @return array<string, BookingTypeInterface> */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * key => label map for selects and validation `in:` rules.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(
            static fn (BookingTypeInterface $type): string => $type->label(),
            $this->types,
        );
    }
}
