<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use App\Models\Payment;

/**
 * Phase 4E.2 — "an attempt is already open, and here it is".
 *
 * A plain PaymentException told the caller only that something went
 * wrong, which forced the checkout layer to re-query and guess. Race
 * losers need the WINNER'S attempt in hand so they can converge on it
 * (spec Part 4): a second request for the same purchase should end up
 * looking at the same gateway order, not at an error page.
 *
 * Carrying the payment on the exception keeps that convergence exact —
 * the caller resumes the attempt that actually won, never one it
 * re-derived a moment later and hoped was the same.
 */
final class PaymentAttemptAlreadyOpenException extends PaymentException
{
    private function __construct(
        public readonly Payment $attempt,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Payment $attempt): self
    {
        return new self($attempt, 'A payment attempt is already in progress for this item.');
    }
}
