<?php

declare(strict_types=1);

namespace App\Notifications\Templates\Exceptions;

use RuntimeException;

/**
 * Thrown when template TEXT (almost always an admin override)
 * references a `{{variable}}` not in that template's allowlist.
 * Callers should catch this and re-render with the code-owned default
 * rather than let a bad edit break real notification delivery.
 */
final class UnknownTemplateVariableException extends RuntimeException {}
