<?php

declare(strict_types=1);

namespace App\Package\Exceptions;

use RuntimeException;

/**
 * Base exception for the Package domain (Personalized Instructor
 * Package Proposal & Admin Approval). Callers may catch this to
 * handle any package failure; subclasses carry specifics.
 */
class PackageException extends RuntimeException {}
