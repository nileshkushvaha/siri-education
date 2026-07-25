<?php

declare(strict_types=1);

namespace App\Compliance\Exceptions;

use RuntimeException;

/** A review action was attempted without a required input (e.g. a missing resolution/dismissal reason). */
final class ComplianceValidationException extends RuntimeException {}
