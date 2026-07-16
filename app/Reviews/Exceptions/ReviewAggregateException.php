<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

use RuntimeException;

/** Rating aggregate/contribution integrity violation — a guarded column on InstructorRatingAggregate or ReviewRatingContribution changed outside its authoritative action, or a delta would drive a sum/count negative. */
class ReviewAggregateException extends RuntimeException {}
