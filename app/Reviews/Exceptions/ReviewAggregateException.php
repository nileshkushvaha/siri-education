<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

use RuntimeException;

/** Instructor rating aggregate integrity violation — a guarded column changed outside InstructorRatingAggregateService, or a delta would drive a sum/count negative. */
class ReviewAggregateException extends RuntimeException {}
