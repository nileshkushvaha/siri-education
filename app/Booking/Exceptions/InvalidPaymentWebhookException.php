<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/** Unverifiable or malformed webhook — answered with 401, never processed. */
final class InvalidPaymentWebhookException extends BookingException {}
