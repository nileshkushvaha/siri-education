<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/** Transient provider failure while fetching attendance — the meeting stays eligible for a later retry. */
final class AttendanceSyncUnavailableException extends BookingException {}
