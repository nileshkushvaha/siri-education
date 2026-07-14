<?php

declare(strict_types=1);

namespace App\Feedback\Exceptions;

use RuntimeException;

/** The lesson/booking/participant state does not permit feedback submission. */
class InstructorStudentFeedbackException extends RuntimeException {}
