<?php

declare(strict_types=1);

namespace App\Homework\Exceptions;

/**
 * Thrown when a homework assignment's educational
 * context (lesson/booking and/or learning plan) is missing, foreign to
 * the student/instructor relationship, or in an ineligible lifecycle
 * state. Extends HomeworkException so existing UI catch blocks surface
 * the message as a validation error.
 */
class InvalidHomeworkContextException extends HomeworkException {}
