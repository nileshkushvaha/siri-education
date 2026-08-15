<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\StudentPackagePurchase;
use App\Payments\Contracts\Payable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PAY-4A — the boundary between the generic payment-attempt kernel and
 * the domains that use it.
 *
 * The kernel's job is narrow: turn a Payable into provider attempts and
 * record what the provider said. What a settled payment MEANS is the
 * domain's job — entitlements for packages, booking confirmation and
 * invoices for bookings. Those two responsibilities drifting into each
 * other is the specific failure this phase has to prevent, because a
 * second Payable type is exactly the pressure that causes it.
 *
 * These are static source guards on purpose: they fail when someone
 * writes the coupling, not merely when a test happens to exercise it.
 */
class PaymentAttemptKernelBoundaryGuardTest extends TestCase
{
    private const string KERNEL_DIR = 'app/Payments';

    /** Obligations are payable. The things being bought are not. */
    public function test_booking_payment_is_payable_and_booking_is_not(): void
    {
        $this->assertTrue(
            (new ReflectionClass(BookingPayment::class))->implementsInterface(Payable::class),
            'BookingPayment must implement Payable — it is the booking commercial obligation.',
        );

        $this->assertFalse(
            (new ReflectionClass(Booking::class))->implementsInterface(Payable::class),
            'Booking must NOT implement Payable. Option A was rejected: most bookings '
            .'(package-funded, free demo, not-required) are never payable at all.',
        );
    }

    /** Aliases are append-only — a changed value orphans every historical payment row. */
    public function test_payable_morph_aliases_are_stable_and_distinct(): void
    {
        $this->assertSame('booking_payment', BookingPayment::PAYABLE_TYPE);
        $this->assertSame('package_purchase', StudentPackagePurchase::PAYABLE_TYPE);
    }

    public function test_morph_map_registers_both_payables_without_a_fqcn(): void
    {
        $source = $this->strippedSource(self::repoPath('app/Providers/PaymentServiceProvider.php'));

        $this->assertStringContainsString('BookingPayment::PAYABLE_TYPE', $source);
        $this->assertStringContainsString('StudentPackagePurchase::PAYABLE_TYPE', $source);
        $this->assertStringNotContainsString("'App\\Models\\", $source);
    }

    /**
     * The attempt kernel may know Payable, amount, currency, owner,
     * reference and provider. It must not know what any domain sells.
     */
    public function test_attempt_kernel_holds_no_domain_vocabulary(): void
    {
        $forbidden = [
            'StudentPackagePurchase', 'PackageProposal', 'StudentPackageEntitlement',
            'PackageEntitlementService', 'Booking::', 'BookingPayment',
            'Invoice', 'WalletLedgerEntry', 'WalletService', 'Lesson',
        ];

        foreach ($this->kernelFiles() as $file) {
            $source = $this->strippedSource($file);

            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $source,
                    sprintf(
                        '%s references "%s". The generic attempt kernel must depend on the Payable '
                        .'contract only — domain meaning belongs to the domain settlement service.',
                        str_replace(self::repoPath('').'/', '', $file),
                        $term,
                    ),
                );
            }
        }
    }

    /**
     * No `if ($payable instanceof …)`. A payable-type switch inside the
     * kernel is how a generic ledger quietly becomes two hard-coded
     * ones.
     */
    public function test_attempt_kernel_does_not_branch_on_payable_type(): void
    {
        foreach ($this->kernelFiles() as $file) {
            $source = $this->strippedSource($file);

            $this->assertStringNotContainsString(
                'instanceof',
                $source,
                sprintf(
                    '%s branches on a concrete type. Add the behaviour to the Payable contract '
                    .'or to the domain settlement service instead.',
                    str_replace(self::repoPath('').'/', '', $file),
                ),
            );
        }
    }

    /**
     * Invoices are Booking-domain behaviour triggered by Booking
     * obligation settlement. Attaching them to a generic Paid attempt
     * would let a PACKAGE payment mint a booking invoice.
     */
    public function test_generic_payment_success_does_not_create_invoices(): void
    {
        foreach ($this->kernelFiles() as $file) {
            $source = $this->strippedSource($file);

            foreach (['Invoice', 'generateInvoice', 'InvoiceService'] as $term) {
                $this->assertStringNotContainsString($term, $source, 'Invoice generation must stay in the Booking domain.');
            }
        }
    }

    /**
     * Refund policy — provider refund vs wallet credit vs package unit
     * restoration — differs per domain. Generic Payment represents
     * collection attempts only.
     */
    public function test_generic_payment_does_not_own_refund_or_wallet_policy(): void
    {
        foreach ($this->kernelFiles() as $file) {
            $source = $this->strippedSource($file);

            foreach (['refund', 'Refund', 'wallet', 'Wallet'] as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $source,
                    'Refund and wallet policy belong to the domain, not the attempt kernel.',
                );
            }
        }
    }

    /** Every Payable method must be answerable from stored obligation data. */
    public function test_payable_contract_stays_minimal(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(Payable::class))->getMethods(),
        );

        sort($methods);

        $this->assertSame([
            'paymentAmountMinor',
            'paymentCurrencyCode',
            'paymentMetadata',
            'paymentPayableId',
            'paymentPayableType',
            'paymentReference',
            'paymentUserId',
        ], $methods, 'Growing the Payable contract pushes domain concerns into every payable.');
    }

    /**
     * BookingPayment's Payable answers must be snapshot reads. Pricing
     * resolution, country lookups, or FX inside them would let a
     * historical obligation change value.
     */
    public function test_booking_payment_payable_methods_do_not_reprice(): void
    {
        $source = $this->strippedSource(self::repoPath('app/Models/BookingPayment.php'));

        foreach (['PricingMatrix', 'PricingService', 'resolvePrice', 'convertCurrency', 'exchangeRate', 'auth()'] as $term) {
            $this->assertStringNotContainsString(
                $term,
                $source,
                'A booking obligation must answer from its own stored snapshot, never by recalculating.',
            );
        }
    }

    /** Resolved without base_path(): these guards read source, so they need no booted app. */
    private static function repoPath(string $relative): string
    {
        return rtrim(dirname(__DIR__, 2).'/'.$relative, '/');
    }

    /** @return list<string> */
    private function kernelFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::repoPath(self::KERNEL_DIR))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertNotEmpty($files, 'Kernel directory scan found nothing — the guard would pass vacuously.');

        return $files;
    }

    /** Executable source only, so a comment naming a banned term never trips the scan. */
    private function strippedSource(string $file): string
    {
        $kept = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $kept .= $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }
}
