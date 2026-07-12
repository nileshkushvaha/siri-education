<?php

declare(strict_types=1);

namespace App\Lessons\Exceptions;

/** Outcome finalization rejected: rule violation (timing, evidence, technical issue, cancelled booking). */
class LessonOutcomeException extends LessonException {}
