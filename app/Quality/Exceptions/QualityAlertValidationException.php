<?php

declare(strict_types=1);

namespace App\Quality\Exceptions;

use RuntimeException;

/** A resolution action was attempted without a required input (e.g. a missing resolution reason). */
final class QualityAlertValidationException extends RuntimeException {}
