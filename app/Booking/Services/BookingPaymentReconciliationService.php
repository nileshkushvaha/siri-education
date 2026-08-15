<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Enums\BookingPaymentReconciliationIssueStatus;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\BookingPayment;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\PaymentGatewaySettings;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the collection-side reconciliation sweep and issue queue — the
 * provider-neutral counterpart to InstructorPayoutReconciliationService,
 * deliberately reusing the exact same shape (chunked reconcileDue(),
 * DB-deduplicated issues, idempotent finalization) but never sharing
 * code or a table with the payout domain. Every state transition it
 * makes goes through BookingPaymentService::applyProviderStatus() —
 * reconciliation never applies a financial effect through a separate
 * code path than the webhook handler does.
 */
final class BookingPaymentReconciliationService implements BookingPaymentReconciliationServiceInterface
{
    private const string LOG_NAME = 'payments';

    public function __construct(
        private readonly BookingPaymentServiceInterface $payments,
        private readonly PaymentProviderResolver $providers,
        private readonly PaymentGatewaySettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * How many reconciliation windows an attempt may be ignored for
     * before it stops being "in flight" and becomes operator-visible.
     * Derived from the existing sweep cadence, never a second timeout.
     */
    private const int STALE_WINDOW_MULTIPLIER = 6;

    public function reconcileDue(int $limit = 200): int
    {
        if (! $this->settings->booking_payment_reconciliation_enabled) {
            return 0;
        }

        $cutoff = now()->subMinutes(max(1, $this->settings->booking_payment_unknown_timeout_minutes));

        $payments = BookingPayment::query()
            ->reconciliationDue($cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $examined = 0;

        foreach ($payments as $payment) {
            $this->reconcileOne($payment, $cutoff);
            $examined++;
        }

        return $examined;
    }

    public function reconcileAttempt(BookingPayment $payment): BookingPayment
    {
        $this->reconcileOne($payment);

        return BookingPayment::query()->whereKey($payment->id)->firstOrFail();
    }

    private function reconcileOne(BookingPayment $payment, ?CarbonInterface $cutoff = null): void
    {
        $cutoff ??= now()->subMinutes(max(1, $this->settings->booking_payment_unknown_timeout_minutes));

        if ($payment->provider_order_id === null) {
            $payment->forceFill(['last_synced_at' => now()])->save();

            return;
        }

        try {
            $provider = $this->providers->resolve($payment->provider);
            $status = $provider->fetchStatus($payment->provider_order_id);
        } catch (GatewayRequestException|BookingException $e) {
            $this->raiseIssue($payment, BookingPaymentReconciliationIssueType::ProviderUnavailable, BookingPaymentReconciliationSeverity::Warning, $e->getMessage());
            $payment->forceFill(['last_synced_at' => now()])->save();

            return;
        }

        $wasUnknown = $payment->status === BookingPaymentRecordStatus::Unknown;
        $before = $payment->status;

        $updated = $this->payments->applyProviderStatus($payment, $status);

        if ($updated->status === $before && $before !== BookingPaymentRecordStatus::Unknown) {
            BookingPayment::query()->whereKey($payment->id)->update(['last_synced_at' => now()]);

            return;
        }

        if ($wasUnknown && $updated->status->isTerminal()) {
            $this->resolveOpenIssues($payment, BookingPaymentReconciliationIssueType::UnknownPaymentOutcome, 'auto_reconciled', 'Provider outcome confirmed on a later reconciliation pass.');
        }

        if ($updated->status === BookingPaymentRecordStatus::Unknown && ! $wasUnknown) {
            $this->raiseIssue($payment, BookingPaymentReconciliationIssueType::UnknownPaymentOutcome, BookingPaymentReconciliationSeverity::Warning, 'Reconciliation could not confirm the provider outcome.');
        }

        $this->detectStaleProcessing($updated, $cutoff);
        $this->resolveRecoveredIssues($updated, $before);
    }

    /**
     * An attempt the provider keeps declining to resolve.
     *
     * The provider is reachable and answering — it simply will not call
     * this settled — so the sweep would otherwise poll it forever with
     * nobody watching. Mirrors the package flow's StaleProcessing, and
     * uses the SAME threshold the sweep already runs on
     * (`booking_payment_unknown_timeout_minutes`) rather than inventing
     * a second unrelated timeout: an attempt is stale once it has been
     * ignored for several of its own reconciliation windows.
     *
     * Makes an incident. Never fails the payment: we do not know the
     * money is gone, only that the provider will not say.
     */
    private function detectStaleProcessing(BookingPayment $payment, CarbonInterface $cutoff): void
    {
        if ($payment->status !== BookingPaymentRecordStatus::Processing) {
            return;
        }

        $staleAfter = $cutoff->copy()->subMinutes(
            max(1, $this->settings->booking_payment_unknown_timeout_minutes) * self::STALE_WINDOW_MULTIPLIER,
        );

        if ($payment->created_at !== null && $payment->created_at->lt($staleAfter)) {
            $this->raiseIssue(
                $payment,
                BookingPaymentReconciliationIssueType::StaleProcessing,
                BookingPaymentReconciliationSeverity::Warning,
                'This payment has been awaiting a provider outcome far longer than expected.',
            );
        }
    }

    /**
     * Closes incidents a later pass has genuinely disproved, so the queue
     * only ever shows problems that are still real.
     *
     * Deliberately narrow. ProviderUnavailable and StaleProcessing are
     * statements about our ABILITY to learn the outcome, so any definite
     * outcome clears them. AmountMismatch, CurrencyMismatch,
     * ProviderSuccessLocalIncomplete, WalletCreditFailed and
     * LateSuccessResolutionFailed are statements about MONEY, and are
     * never auto-closed — a replayed webhook or a fresh poll does not
     * put a student's money where it belongs. Those stay open until an
     * operator closes them.
     */
    private function resolveRecoveredIssues(BookingPayment $payment, BookingPaymentRecordStatus $before): void
    {
        if ($payment->status === $before || ! $payment->status->isTerminal()) {
            return;
        }

        foreach ([
            BookingPaymentReconciliationIssueType::ProviderUnavailable,
            BookingPaymentReconciliationIssueType::StaleProcessing,
        ] as $type) {
            $this->resolveOpenIssues($payment, $type, 'auto_reconciled', 'The provider returned a definitive outcome on a later pass.');
        }
    }

    public function raiseIssue(
        BookingPayment $payment,
        BookingPaymentReconciliationIssueType $type,
        BookingPaymentReconciliationSeverity $severity,
        string $safeSummary,
    ): BookingPaymentReconciliationIssue {
        $existing = BookingPaymentReconciliationIssue::query()
            ->open()
            ->where('booking_payment_id', $payment->id)
            ->where('type', $type)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'last_detected_at' => now(),
                'severity' => $this->higherSeverity($existing->severity, $severity),
                'provider_status' => $payment->provider_payment_id,
                'local_status' => $payment->status->value,
            ])->save();

            return $existing;
        }

        try {
            $issue = DB::transaction(function () use ($payment, $type, $severity, $safeSummary): BookingPaymentReconciliationIssue {
                $issue = BookingPaymentReconciliationIssue::query()->create([
                    'reference' => $this->generateReference(),
                    'booking_payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'type' => $type,
                    'severity' => $severity,
                    'local_status' => $payment->status->value,
                    'provider_status' => $payment->provider_payment_id,
                    'amount_minor' => $payment->amount_minor,
                    'currency_code' => $payment->currency_code,
                    'safe_summary' => $safeSummary,
                    'first_detected_at' => now(),
                    'last_detected_at' => now(),
                ]);
                $issue->forceFill(['status' => BookingPaymentReconciliationIssueStatus::Open])->save();

                return $issue;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent sweep won the race to create the same open
            // issue — the DB-level dedupe key (see the creating
            // migration) is the backstop; fetch and update it instead.
            $existing = BookingPaymentReconciliationIssue::query()
                ->open()
                ->where('booking_payment_id', $payment->id)
                ->where('type', $type)
                ->firstOrFail();
            $existing->forceFill(['last_detected_at' => now()])->save();

            return $existing;
        }

        $this->audit->logSystem(self::LOG_NAME, 'booking_payment_reconciliation_issue_detected', sprintf('Reconciliation issue %s raised: %s', $issue->reference, $safeSummary), $issue, ['type' => $type->value, 'severity' => $severity->value]);

        return $issue;
    }

    public function assign(BookingPaymentReconciliationIssue $issue, User $assignee, User $actor): BookingPaymentReconciliationIssue
    {
        $issue->forceFill(['assigned_to' => $assignee->id])->save();

        $this->audit->logUser($actor, self::LOG_NAME, 'booking_payment_reconciliation_issue_assigned', sprintf('Reconciliation issue %s assigned to %s.', $issue->reference, $assignee->name), $issue);

        return $issue;
    }

    public function startInvestigating(BookingPaymentReconciliationIssue $issue, User $actor): BookingPaymentReconciliationIssue
    {
        if ($issue->status !== BookingPaymentReconciliationIssueStatus::Open) {
            throw new BookingException('Only an open issue can move to investigating.');
        }

        $issue->forceFill(['status' => BookingPaymentReconciliationIssueStatus::Investigating])->save();

        $this->audit->logUser($actor, self::LOG_NAME, 'booking_payment_reconciliation_issue_investigating', sprintf('Reconciliation issue %s marked as investigating.', $issue->reference), $issue);

        return $issue;
    }

    public function resolve(BookingPaymentReconciliationIssue $issue, User $actor, string $resolutionType, string $note): BookingPaymentReconciliationIssue
    {
        if (trim($note) === '') {
            throw new BookingException('A resolution note (the evidence) is required.');
        }

        if (! $issue->status->isOpen()) {
            throw new BookingException('This issue is already closed.');
        }

        $issue->forceFill([
            'status' => BookingPaymentReconciliationIssueStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution_type' => $resolutionType,
            'resolution_note' => $note,
        ])->save();

        $this->audit->logOverride($actor, self::LOG_NAME, 'booking_payment_reconciliation_issue_resolved', sprintf('Reconciliation issue %s resolved.', $issue->reference), $note, $issue, ['resolution_type' => $resolutionType]);

        return $issue;
    }

    private function resolveOpenIssues(BookingPayment $payment, BookingPaymentReconciliationIssueType $type, string $resolutionType, string $note): void
    {
        BookingPaymentReconciliationIssue::query()
            ->open()
            ->where('booking_payment_id', $payment->id)
            ->where('type', $type)
            ->get()
            ->each(function (BookingPaymentReconciliationIssue $issue) use ($resolutionType, $note): void {
                $issue->forceFill([
                    'status' => BookingPaymentReconciliationIssueStatus::Resolved,
                    'resolved_at' => now(),
                    'resolution_type' => $resolutionType,
                    'resolution_note' => $note,
                ])->save();

                $this->audit->logSystem(self::LOG_NAME, 'booking_payment_reconciliation_issue_resolved', sprintf('Reconciliation issue %s auto-resolved.', $issue->reference), $issue);
            });
    }

    private function higherSeverity(BookingPaymentReconciliationSeverity $a, BookingPaymentReconciliationSeverity $b): BookingPaymentReconciliationSeverity
    {
        $rank = ['info' => 0, 'warning' => 1, 'critical' => 2];

        return $rank[$b->value] > $rank[$a->value] ? $b : $a;
    }

    private function generateReference(): string
    {
        do {
            $reference = sprintf('BPRI-%s-%s', now()->format('Ym'), strtoupper(Str::random(8)));
        } while (BookingPaymentReconciliationIssue::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
