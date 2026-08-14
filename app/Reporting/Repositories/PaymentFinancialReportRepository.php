<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\User;
use App\Package\Enums\PackagePurchaseStatus;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Finance\ReconciliationIssueRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only payment-collection and reconciliation aggregates (Phase
 * 18E). Attempt-level `booking_payments.status = 'captured'` is the
 * collection ground truth (`Refunded` was also captured before being
 * refunded); the booking-level `payment_status` snapshot drives only
 * the separately-labeled gross-paid-booking-value figure. Integer
 * minor units, grouped by currency, no float, no cross-currency total.
 */
final class PaymentFinancialReportRepository
{
    private const array TERMINAL_STATUSES = ['captured', 'failed', 'cancelled', 'expired', 'refunded'];

    private const array CAPTURED_AT_SOME_POINT = ['captured', 'refunded'];

    public function summary(ReportingPeriod $period, ReportFilters $filters): PaymentFinancialSummaryData
    {
        $statusCounts = $this->scopedAttempts($period, $filters)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $attempts = array_sum($statusCounts);
        $capturedEver = array_sum(array_intersect_key($statusCounts, array_flip(self::CAPTURED_AT_SOME_POINT)));
        $terminal = array_sum(array_intersect_key($statusCounts, array_flip(self::TERMINAL_STATUSES)));

        $capturedByCurrency = $this->scopedAttempts($period, $filters)
            ->whereIn('status', self::CAPTURED_AT_SOME_POINT)
            ->selectRaw('currency_code, count(*) as aggregate, COALESCE(SUM(amount_minor), 0) as amount')
            ->groupBy('currency_code')
            ->get();

        return new PaymentFinancialSummaryData(
            attempts: $attempts,
            captured: $capturedEver,
            failed: $statusCounts['failed'] ?? 0,
            pending: $attempts - $terminal,
            cancelledOrExpired: ($statusCounts['cancelled'] ?? 0) + ($statusCounts['expired'] ?? 0),
            successRate: $terminal > 0 ? round($capturedEver / $terminal * 100, 1) : null,
            capturedAmountByCurrency: $capturedByCurrency->pluck('amount', 'currency_code')->map(fn ($v) => (int) $v)->all(),
            averageCapturedByCurrency: $capturedByCurrency
                ->mapWithKeys(fn ($row) => [(string) $row->currency_code => (int) $row->aggregate > 0 ? intdiv((int) $row->amount, (int) $row->aggregate) : 0])
                ->all(),
            grossPaidBookingValueByCurrency: $this->grossPaidBookingValueByCurrency($period, $filters),
            packagePurchaseCollectedByCurrency: $this->packagePurchaseCollectedByCurrency($period, $filters),
            packagePurchasesSold: $this->packagePurchasesSold($period, $filters),
            byProviderStatus: $this->scopedAttempts($period, $filters)
                ->selectRaw('provider, status, count(*) as aggregate')
                ->groupBy('provider', 'status')
                ->orderBy('provider')
                ->get()
                ->map(fn ($row) => ['provider' => (string) $row->provider, 'status' => (string) $row->status, 'count' => (int) $row->aggregate])
                ->all(),
            duplicateProviderEvents: (int) DB::table('booking_payment_provider_events')
                ->whereNotNull('duplicate_of_id')
                ->where('received_at', '>=', $period->startUtc)
                ->where('received_at', '<', $period->endUtcExclusive)
                ->count(),
            openReconciliationIssues: (int) DB::table('booking_payment_reconciliation_issues')->where('status', 'open')->count(),
        );
    }

    /**
     * COMMERCIAL VALUE, never labeled revenue (§7 Outcome B): SUM of
     * `bookings.price` where `payment_status = 'paid'`, by booking
     * `created_at` — the same semantics as the legacy Booking Reports
     * figure but grouped by currency. `bookings.price` is decimal
     * major units; the DECIMAL SQL sum is converted to integer minor
     * units per currency via the existing MoneyFormatter (never float).
     *
     * @return array<string, int>
     */
    public function grossPaidBookingValueByCurrency(ReportingPeriod $period, ReportFilters $filters): array
    {
        $rows = DB::table('bookings')
            ->whereNull('deleted_at')
            ->where('payment_status', 'paid')
            ->whereNotNull('currency')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->when($filters->currencyCode !== null, fn ($q) => $q->where('currency', $filters->currencyCode))
            ->when($filters->instructorId !== null, fn ($q) => $q->where('instructor_id', $filters->instructorId))
            ->selectRaw('currency, SUM(price) as total')
            ->groupBy('currency')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $result[$currency] = MoneyFormatter::toMinor((string) $row->total, MoneyFormatter::minorUnitsFor($currency));
        }

        return $result;
    }

    /**
     * Phase 4E.3 (PKG-AUD-012) — money actually collected for
     * PERSONALIZED PACKAGES, by currency, in minor units.
     *
     * Package sales were invisible to every financial report. Package
     * purchases settle through the generic `payments` path, not
     * `booking_payments`, so collections never saw them; and their
     * lessons book as PackageFunded, which booking-value metrics
     * correctly exclude. The result was real revenue that appeared
     * nowhere.
     *
     * RECOGNIZED ONCE, AT SETTLEMENT. The source is the PURCHASE, not
     * its payment attempts: a purchase may accumulate many attempts
     * (a declined card, then a successful retry), and summing attempts
     * would count a single sale several times. `status = paid` is
     * reachable only through verified settlement, and
     * UNIQUE(proposal_id) means one proposal yields one purchase — so
     * one sale contributes exactly one amount, forever.
     *
     * DELIBERATELY NOT ALLOCATED PER LESSON. No
     * `amount / total_quantity` figure is produced, and paid versus
     * bonus units are not distinguished: the package is one commercial
     * sale, and splitting it across deliveries would invent an accrual
     * policy nobody approved. It is likewise never added to
     * `grossPaidBookingValueByCurrency` — that metric counts bookings
     * whose money came through the booking pipeline, and a
     * package-funded booking's value was already collected here.
     *
     * `amount_minor` is already integer minor units (unlike
     * `bookings.price`, which is decimal major units), so no
     * MoneyFormatter conversion is needed. Grouped by `currency_code`
     * for the same reason every other figure here is: currencies are
     * never summed together, and no FX conversion exists.
     *
     * @return array<string, int> currency code => minor units
     */
    public function packagePurchaseCollectedByCurrency(ReportingPeriod $period, ReportFilters $filters): array
    {
        $rows = DB::table('student_package_purchases')
            ->where('status', PackagePurchaseStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $period->startUtc)
            ->where('paid_at', '<', $period->endUtcExclusive)
            ->when($filters->currencyCode !== null, fn ($q) => $q->where('currency_code', $filters->currencyCode))
            ->selectRaw('currency_code, SUM(amount_minor) as total')
            ->groupBy('currency_code')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->currency_code] = (int) $row->total;
        }

        return $result;
    }

    /** How many personalized packages were sold in the period — one per settled purchase. */
    public function packagePurchasesSold(ReportingPeriod $period, ReportFilters $filters): int
    {
        return (int) DB::table('student_package_purchases')
            ->where('status', PackagePurchaseStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $period->startUtc)
            ->where('paid_at', '<', $period->endUtcExclusive)
            ->when($filters->currencyCode !== null, fn ($q) => $q->where('currency_code', $filters->currencyCode))
            ->count();
    }

    /** Bounded, paginated open payment reconciliation issues — severity/age first. */
    public function paginatedReconciliationIssues(User $viewer, int $perPage = 25): LengthAwarePaginator
    {
        return BookingPaymentReconciliationIssue::query()
            ->where('status', 'open')
            ->orderByRaw("FIELD(severity, 'critical', 'error', 'warning', 'info')")
            ->orderBy('first_detected_at')
            ->paginate($perPage)
            ->through(fn (BookingPaymentReconciliationIssue $issue): ReconciliationIssueRow => new ReconciliationIssueRow(
                reference: $issue->reference,
                provider: $issue->provider,
                typeLabel: ucwords(str_replace('_', ' ', $issue->getRawOriginal('type') ?? '')),
                severityLabel: ucfirst((string) $issue->getRawOriginal('severity')),
                statusLabel: ucfirst((string) $issue->getRawOriginal('status')),
                amountMinor: $issue->amount_minor !== null ? (int) $issue->amount_minor : null,
                currency: $issue->currency_code,
                safeSummary: (string) $issue->safe_summary,
                firstDetectedAtUtc: CarbonImmutable::parse($issue->getRawOriginal('first_detected_at'), 'UTC'),
                drillDownUrl: Gate::forUser($viewer)->allows('viewAny', BookingPaymentReconciliationIssue::class)
                    ? BookingPaymentReconciliationIssueResource::getUrl('index')
                    : null,
            ));
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function scopedAttempts(ReportingPeriod $period, ReportFilters $filters): Builder
    {
        $query = DB::table('booking_payments')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        if ($filters->currencyCode !== null) {
            $query->where('currency_code', $filters->currencyCode);
        }

        return $query;
    }
}
