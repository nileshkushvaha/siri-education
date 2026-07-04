<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\UnknownBookingTypeException;
use App\Models\BookingType;
use Illuminate\Support\Collection;

interface BookingTypeRepositoryInterface
{
    public function findByKey(string $key): ?BookingType;

    /** @return Collection<int, BookingType> active types in display order */
    public function allActive(): Collection;

    /**
     * @throws UnknownBookingTypeException when no row exists for the key
     * @throws BookingException when the type exists but is inactive
     */
    public function requireActiveByKey(string $key): BookingType;
}
