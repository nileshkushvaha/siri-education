<?php

declare(strict_types=1);

namespace App\Curriculum\Exceptions;

use RuntimeException;

/**
 * Single business-rule exception for Education System configuration
 * and academic-context resolution failures (mirrors CurriculumException
 * — the Curriculum domain uses one exception class per concern with
 * distinct messages, not an exception subclass per failure, unlike
 * e.g. app/Booking/Exceptions/*). Thrown by EducationSystemService
 * and AcademicContextResolver.
 */
final class AcademicContextException extends RuntimeException {}
