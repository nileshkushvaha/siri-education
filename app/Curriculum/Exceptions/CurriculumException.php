<?php

declare(strict_types=1);

namespace App\Curriculum\Exceptions;

use RuntimeException;

/** Single business-rule exception for the curriculum domain (mirrors PromotionalCreditException). */
final class CurriculumException extends RuntimeException {}
