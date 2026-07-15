<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/** A booking archive/restore request violated an archival-workflow rule (non-terminal, missing reason, wrong state). */
class BookingArchivalException extends BookingException {}
