<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Communication;

use App\Reporting\DTOs\Operations\LabeledCountRow;

/**
 * Notification activity. Version 1 stores TWO authoritative
 * notification records: in-app database notifications (`notifications`:
 * type, created_at, read_at) and the dispatch idempotency log
 * (`notification_dispatch_log`: one claimed row per deduplicated
 * business notification). No delivery attempt, delivery status,
 * channel outcome, provider callback or retry state is recorded
 * anywhere — so delivery rate, provider performance, email/SMS/WhatsApp
 * outcomes, critical-failure and retry-exhaustion metrics are all
 * structurally unavailable (§4C delivery-rate gate failed: no attempt
 * model exists to define a denominator). Nothing here is inferred from
 * missing callbacks.
 *
 * `readRate` = among in-app notifications CREATED in the period, the
 * share with `read_at` set as of now (explicitly an as-of-now read
 * state over a period cohort, not a delivery outcome). Null at zero
 * denominator. Type labels are class basenames — never payloads.
 *
 * @param  list<LabeledCountRow>  $byType  top-N in-app notification class basenames
 * @param  list<LabeledCountRow>  $dedupByClass  top-N dispatch-log claim class basenames
 */
final readonly class NotificationActivityData
{
    public function __construct(
        public int $inAppCreatedInPeriod,
        public int $inAppReadOfPeriodCohort,
        public ?float $readRate,
        public int $currentUnread,
        public array $byType,
        public int $dedupClaimsInPeriod,
        public array $dedupByClass,
    ) {}
}
