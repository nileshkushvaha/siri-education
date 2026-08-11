<?php

declare(strict_types=1);

namespace App\Curriculum\Exceptions;

use RuntimeException;

/** Single business-rule exception for instructor academic eligibility (mirrors AcademicContextException/CurriculumException). */
final class InstructorAcademicEligibilityException extends RuntimeException {}
