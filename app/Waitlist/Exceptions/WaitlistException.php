<?php

declare(strict_types=1);

namespace App\Waitlist\Exceptions;

use RuntimeException;

/** Base exception for the Waitlist domain — carries a message safe to show the student directly. */
class WaitlistException extends RuntimeException {}
