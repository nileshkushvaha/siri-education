<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAY-2 — the queues describe only what the platform can actually do.
 *
 * PAY-AUD-002: the Booking queue declared twelve issue types and had
 * producers for two, while its filter offered all twelve. An operator
 * could search for a state the platform is incapable of generating and
 * read the empty result as reassurance.
 *
 * PAY-AUD-003: the two live sets were disjoint, so the same financial
 * failure meant different things on either side of the platform.
 *
 * Dormant cases are RETAINED, not deleted — production may hold rows this
 * environment cannot see, and a removed case could not be hydrated.
 */
class ReconciliationIssueTruthfulnessTest extends TestCase
{
    use RefreshDatabase;

    /** Every Booking type with a real producer after PAY-2. */
    private const array EXPECTED_LIVE_BOOKING = [
        'unknown_payment_outcome',
        'provider_unavailable',
        'amount_mismatch',
        'currency_mismatch',
        'stale_processing',
        'provider_success_local_incomplete',
        'late_success_resolution_failed',
        'wallet_credit_failed',
    ];

    /** Retained for hydration; never offered as something to search for. */
    private const array EXPECTED_DORMANT_BOOKING = [
        'unknown_payment_reference',
        'duplicate_provider_reference',
        'local_success_provider_mismatch',
        'refund_status_mismatch',
    ];

    // ── Truthfulness ────────────────────────────────────────────────────

    public function test_the_live_booking_set_is_exactly_what_has_producers(): void
    {
        $live = array_map(
            static fn (BookingPaymentReconciliationIssueType $t): string => $t->value,
            BookingPaymentReconciliationIssueType::live(),
        );

        sort($live);
        $expected = self::EXPECTED_LIVE_BOOKING;
        sort($expected);

        $this->assertSame($expected, $live);
    }

    public function test_dormant_types_are_retained_and_still_hydrate(): void
    {
        // The guarantee that makes hiding safe: a historical row written
        // before PAY-2 must still resolve to an enum case.
        foreach (self::EXPECTED_DORMANT_BOOKING as $value) {
            $case = BookingPaymentReconciliationIssueType::tryFrom($value);

            $this->assertNotNull($case, "{$value} must remain hydratable");
            $this->assertFalse($case->isLive());
            $this->assertNotSame('', $case->label());
        }
    }

    public function test_the_operator_filter_advertises_only_live_types(): void
    {
        $table = (string) file_get_contents(base_path(
            'app/Filament/Resources/BookingPaymentReconciliationIssues/Tables/BookingPaymentReconciliationIssuesTable.php'
        ));

        $this->assertStringContainsString('BookingPaymentReconciliationIssueType::live()', $table);
        $this->assertStringNotContainsString('BookingPaymentReconciliationIssueType::cases()', $table);
    }

    public function test_every_live_booking_type_has_a_production_producer(): void
    {
        // Structural guard: a case cannot be declared live without code
        // that raises it. Scans production only — a test or a factory is
        // not a producer.
        $sources = $this->productionSources();

        foreach (BookingPaymentReconciliationIssueType::live() as $type) {
            $needle = 'IssueType::'.$this->caseName($type);

            $this->assertTrue(
                str_contains($sources, $needle),
                "{$type->value} is marked live but nothing in app/ raises it.",
            );
        }
    }

    public function test_dormant_booking_types_are_not_raised_anywhere(): void
    {
        // The converse: if something starts raising a dormant type, its
        // classification is stale and this fails rather than drifting.
        $sources = $this->productionSources();

        foreach (BookingPaymentReconciliationIssueType::cases() as $type) {
            if ($type->isLive()) {
                continue;
            }

            $this->assertFalse(
                str_contains($sources, 'IssueType::'.$this->caseName($type)),
                "{$type->value} is marked dormant but something raises it — reclassify it.",
            );
        }
    }

    // ── PAY-AUD-003 · shared vocabulary ─────────────────────────────────

    public function test_equivalent_collection_failures_mean_the_same_thing_on_both_sides(): void
    {
        // Not identical enums — identical MEANING. Each of these names a
        // failure both architectures can genuinely hit.
        $shared = ['provider_unavailable', 'amount_mismatch', 'currency_mismatch', 'stale_processing'];

        foreach ($shared as $value) {
            $booking = BookingPaymentReconciliationIssueType::tryFrom($value);
            $generic = PaymentReconciliationIssueType::tryFrom($value);

            $this->assertNotNull($booking, "Booking is missing {$value}");
            $this->assertNotNull($generic, "Generic is missing {$value}");
            $this->assertTrue($booking->isLive(), "{$value} must be live on the Booking side");
        }
    }

    public function test_each_side_keeps_the_failures_only_it_can_have(): void
    {
        // Generic-only: an attempt row can exist with no provider
        // reference. A BookingPayment cannot — the provider call creates it.
        $this->assertNotNull(PaymentReconciliationIssueType::tryFrom('missing_provider_reference'));
        $this->assertNull(BookingPaymentReconciliationIssueType::tryFrom('missing_provider_reference'));

        // Booking-only: wallet refunds and late-success recovery are
        // booking-domain workflows and must not leak into the generic
        // collection kernel.
        foreach (['wallet_credit_failed', 'late_success_resolution_failed', 'unknown_payment_outcome'] as $value) {
            $this->assertNotNull(BookingPaymentReconciliationIssueType::tryFrom($value));
            $this->assertNull(PaymentReconciliationIssueType::tryFrom($value));
        }
    }

    public function test_money_held_without_delivery_is_expressible_on_both_sides(): void
    {
        // The worst state either flow can reach. Different names, same
        // meaning: provider confirmed, customer got nothing.
        $this->assertTrue(PaymentReconciliationIssueType::SettlementFailed->isMoneyCollectedWithoutDelivery());
        $this->assertTrue(BookingPaymentReconciliationIssueType::ProviderSuccessLocalIncomplete->isLive());
    }

    // ── Generic queue ───────────────────────────────────────────────────

    public function test_all_six_generic_types_are_live_and_described(): void
    {
        $expected = [
            'amount_mismatch', 'currency_mismatch', 'provider_unavailable',
            'settlement_failed', 'stale_processing', 'missing_provider_reference',
        ];

        $actual = array_map(
            static fn (PaymentReconciliationIssueType $t): string => $t->value,
            PaymentReconciliationIssueType::cases(),
        );

        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        foreach (PaymentReconciliationIssueType::cases() as $type) {
            $this->assertNotSame('', $type->label());
            $this->assertNotSame('', $type->description());
        }
    }

    public function test_settlement_failed_is_the_only_money_held_state(): void
    {
        foreach (PaymentReconciliationIssueType::cases() as $type) {
            $this->assertSame(
                $type === PaymentReconciliationIssueType::SettlementFailed,
                $type->isMoneyCollectedWithoutDelivery(),
            );
        }
    }

    // ── Payment model cast ──────────────────────────────────────────────

    public function test_initialization_claimed_at_hydrates_as_an_immutable_instant(): void
    {
        $payment = Payment::query()->create([
            'payable_type' => StudentPackagePurchase::PAYABLE_TYPE,
            'payable_id' => (string) Str::uuid(),
            'provider' => 'stripe',
            'amount_minor' => 1000,
            'currency_code' => 'INR',
            'status' => PaymentStatus::Pending,
            'idempotency_key' => 'PKG-'.Str::random(10),
        ]);

        $payment->forceFill(['initialization_claimed_at' => now()->subHour()])->save();

        $this->assertInstanceOf(CarbonImmutable::class, $payment->fresh()->initialization_claimed_at);
    }

    public function test_the_initialization_claim_is_still_not_mass_assignable(): void
    {
        // The claim is an atomic conditional UPDATE. Making it fillable
        // would let a caller hand it to create() and quietly defeat the
        // one-open-attempt invariant.
        $this->assertNotContains('initialization_claimed_at', (new Payment)->getFillable());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function caseName(BookingPaymentReconciliationIssueType $type): string
    {
        return $type->name;
    }

    private function productionSources(): string
    {
        $sources = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // The enum's own declaration and classification helper are not
            // producers; neither is the Filament filter.
            if (str_contains($file->getPathname(), 'BookingPaymentReconciliationIssueType.php')
                || str_contains($file->getPathname(), 'Filament')) {
                continue;
            }

            $sources .= (string) file_get_contents($file->getPathname());
        }

        return $sources;
    }
}
