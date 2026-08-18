<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use RuntimeException;

/**
 * A verified-but-malformed recording webhook. Signature checks passed,
 * so the sender is genuine, but the payload is not the shape the
 * adapter expects. Surfaces as a 422 with a generic message — the
 * detail goes to the log, never to the caller.
 */
final class InvalidRecordingWebhookException extends RuntimeException {}
