<?php

declare(strict_types=1);

namespace App\Notifications\Templates\Exceptions;

use RuntimeException;

/**
 * GAP-039 requirement #3 "rejects unknown variables" — thrown when
 * template TEXT (almost always an admin override) references a
 * `{{variable}}` not in that template's allowlist. Callers should
 * catch this and re-render with the code-owned default rather than
 * let a bad edit break real notification delivery (requirement #9).
 */
final class UnknownTemplateVariableException extends RuntimeException {}
