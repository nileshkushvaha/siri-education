<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\UnknownBookingTypeException;
use App\Models\BookingType;
use Illuminate\Support\Collection;

final class BookingTypeRepository implements BookingTypeRepositoryInterface
{
    public function findByKey(string $key): ?BookingType
    {
        return BookingType::query()->where('key', $key)->first();
    }

    public function allActive(): Collection
    {
        return BookingType::query()->active()->ordered()->get();
    }

    public function requireActiveByKey(string $key): BookingType
    {
        $type = $this->findByKey($key) ?? throw UnknownBookingTypeException::forKey($key);

        if (! $type->is_active) {
            throw new BookingException(sprintf('Booking type "%s" is not currently accepting bookings.', $key));
        }

        return $type;
    }
}
