<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown by PreventsHardDeletion — a historical business record was asked to physically delete itself. */
class HistoricalRecordCannotBeDeletedException extends RuntimeException {}
