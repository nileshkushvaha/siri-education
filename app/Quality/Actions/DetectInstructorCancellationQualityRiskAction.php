<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Booking\DTOs\CancelBookingData;
use App\Models\Booking;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\DTOs\QualitySignalData;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;
use App\Quality\Support\InstructorCancellationAttribution;
use App\Quality\Support\QualityAlertFingerprint;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;

/**
 * Consumes a cancelled booking. Only Host-attributed (instructor)
 * cancellations count — student cancellations, system expirations,
 * payment-failure cancellations, and administrator corrections are
 * all different `BookingActor` values and are structurally excluded,
 * never inferred from the booking's cancelled status alone. There is
 * no singular "InstructorCancellation" alert type — only the
 * repeated/threshold type, per spec.
 */
final class DetectInstructorCancellationQualityRiskAction
{
    public function __construct(
        private readonly QualitySignalRepositoryInterface $signals,
        private readonly InstructorQualityAlertRepositoryInterface $alerts,
        private readonly RecordQualityAlertSignalAction $record,
        private readonly ReviewSettings $settings,
    ) {}

    public function execute(Booking $booking, CancelBookingData $context): void
    {
        if (! $this->settings->quality_alerts_enabled) {
            return;
        }

        if (! InstructorCancellationAttribution::isInstructorAttributable($context)) {
            return;
        }

        $instructorId = $booking->host_id;
        $now = CarbonImmutable::now();
        $windowStart = $now->subDays($this->settings->repeated_cancellation_window_days);
        $count = $this->signals->countInstructorAttributedCancellations($instructorId, $windowStart);

        if ($count < $this->settings->repeated_cancellation_count) {
            return;
        }

        $episode = 1 + $this->alerts->countTerminalForInstructorAndType($instructorId, InstructorQualityAlertType::RepeatedInstructorCancellations);

        $this->record->execute(new QualitySignalData(
            instructorId: $instructorId,
            type: InstructorQualityAlertType::RepeatedInstructorCancellations,
            sourceType: QualityAlertSourceType::Booking,
            sourceId: $booking->id,
            fingerprint: QualityAlertFingerprint::forEpisode(InstructorQualityAlertType::RepeatedInstructorCancellations, $instructorId, $episode),
            triggeredAt: $now,
            windowStart: $windowStart,
            windowEnd: $now,
            signalCount: $count,
            summaryMetadata: ['triggering_booking_id' => $booking->id],
            episodeNumber: $episode,
        ));
    }
}
