<?php

declare(strict_types=1);

namespace App\Messaging\Exceptions;

use RuntimeException;

/** Single business-rule exception for the messaging domain (mirrors WaitlistException). */
final class MessagingException extends RuntimeException {}
