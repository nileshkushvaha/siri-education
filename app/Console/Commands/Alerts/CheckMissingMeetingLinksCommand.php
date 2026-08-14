<?php

declare(strict_types=1);

namespace App\Console\Commands\Alerts;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Models\Booking;
use App\Settings\MeetingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SRS §26.36 "Missing Meeting Link Alert" (Critical priority) — no
 * proactive sweep for this existed previously (the
 * SRS_Compliance_Audit finding for SRS-26-6 was "no proactive
 * pre-lesson sweep/alert"). Scoped to confirmed, online bookings whose
 * payment status makes them meeting-eligible at all
 * (BookingMeetingService::isEligible()'s same two payment-status
 * branches), starting within the configured threshold and still ahead
 * of now — a lesson already in progress or past gets no new alert
 * here, since nothing later would help; existing alerts stay open.
 * Requirement #8: one booking's alert failure must never abort the
 * sweep for the rest.
 */
final class CheckMissingMeetingLinksCommand extends Command
{
    protected $signature = 'alerts:check-missing-meeting-links';

    protected $description = 'Alert operations when an upcoming online lesson has no meeting link within the configured threshold.';

    public function handle(MeetingSettings $settings, OperationalAlertService $alerts): int
    {
        $threshold = Carbon::now()->addMinutes(max(1, $settings->missing_meeting_link_threshold_minutes));

        $flagged = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('location_type', BookingLocationType::Online)
            // Every booking that may be delivered needs a working link —
            // including package-funded ones, which would otherwise go
            // unmonitored precisely because nothing was collected at
            // booking time.
            ->whereIn('payment_status', BookingPaymentStatus::deliverable())
            ->where('starts_at', '>', Carbon::now())
            ->where('starts_at', '<=', $threshold)
            ->whereDoesntHave('meeting', fn ($query) => $query
                ->where('status', MeetingStatus::Created)
                ->whereNotNull('join_url'))
            ->with('meeting')
            ->orderBy('starts_at')
            ->cursor()
            ->each(function (Booking $booking) use ($alerts, &$flagged): void {
                try {
                    $alerts->createOrMerge(new OperationalAlertSignal(
                        type: OperationalAlertType::MissingMeetingLink,
                        category: OperationalAlertType::MissingMeetingLink->category(),
                        severity: OperationalAlertSeverity::Critical,
                        title: sprintf('Booking %s has no meeting link', $booking->reference),
                        summary: sprintf(
                            'Booking %s starts at %s and still has no usable meeting link.',
                            $booking->reference,
                            $booking->starts_at?->toDateTimeString(),
                        ),
                        subjectType: Booking::class,
                        subjectId: (string) $booking->id,
                        metadata: ['reference' => $booking->reference, 'starts_at' => $booking->starts_at?->toIso8601String()],
                    ));
                    $flagged++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Missing-meeting-link sweep flagged {$flagged} booking(s).");

        return self::SUCCESS;
    }
}
