<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown by PreventsUpdates — an immutable record was asked to change after creation. */
class ImmutableRecordCannotBeUpdatedException extends RuntimeException {}
