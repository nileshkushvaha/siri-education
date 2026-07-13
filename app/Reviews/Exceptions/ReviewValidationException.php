<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

/** Submitted rating, text, or tag input failed validation — a bad request, not an eligibility-state problem. */
final class ReviewValidationException extends ReviewEligibilityException {}
