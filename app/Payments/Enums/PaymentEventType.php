<?php

declare(strict_types=1);

namespace App\Payments\Enums;

/**
 * A provider webhook/poll outcome, normalized. Deliberately smaller
 * than PaymentStatus: an event says what the PROVIDER reported, not
 * what our attempt's lifecycle should become — the settlement service
 * decides that.
 *
 * `Ignored` is a first-class case rather than a null: providers emit
 * many event types we have no opinion about, and answering "understood,
 * not actionable" is different from "unrecognised".
 */
enum PaymentEventType: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Processing = 'processing';
    case Ignored = 'ignored';
}
