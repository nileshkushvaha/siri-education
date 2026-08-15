<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Package\Services\PackageEntitlementService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 4E.4 (PKG-AUD-015) — there is exactly ONE way a package balance
 * may change.
 *
 * Until this phase there were two. `consumeForLesson()` wrote the
 * immutable consumption ledger, claimed the booking's reservation and
 * incremented `used_quantity`, all in one transaction. Its sibling
 * `consumeLesson()` moved `used_quantity` on its own — no ledger row, no
 * reservation claim, and therefore none of the idempotency that makes a
 * replayed completion safe. It had no production callers, but it was
 * public, plausible-looking, and one call away from silently
 * double-spending a student's package.
 *
 * These guards are deliberately source-level. The invariant is about
 * what code is REACHABLE, not about what a particular run happens to do,
 * and a behavioural test cannot fail for a mutator nobody has called
 * yet. They are written to survive reformatting: they look for the
 * mutation patterns themselves, never line numbers or exact spacing.
 */
class PackageBalanceIntegrityGuardTest extends TestCase
{
    /** Every non-test PHP file in the application. */
    private function applicationFiles(): array
    {
        $files = [];
        $base = dirname(__DIR__, 2).'/app';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function entitlementServiceSource(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(PackageEntitlementService::class))->getFileName(),
        );
    }

    public function test_the_ledger_bypassing_balance_mutator_is_gone(): void
    {
        $source = $this->entitlementServiceSource();

        // Named explicitly: a future reviewer reading this test should
        // learn WHY the method may not come back, not merely that it is
        // absent today.
        $this->assertStringNotContainsString(
            'function consumeLesson(',
            $source,
            'consumeLesson() moved used_quantity without a consumption ledger row or a reservation claim. '
            .'Consumption must go through consumeForLesson(), which does all three atomically.',
        );
    }

    public function test_only_the_canonical_consumption_path_writes_the_balance(): void
    {
        $source = $this->entitlementServiceSource();

        // Three legitimate occurrences, and no more:
        //   1. createFromProposal() seeds it at 0;
        //   2. consumeForLesson() moves it by one;
        //   3. metadata() READS it into the audit payload.
        // A fourth is a new mutation path. Counting rather than pattern-
        // matching each site keeps the guard robust to reformatting
        // while still failing the moment someone adds a writer.
        preg_match_all("/'used_quantity'\s*=>/", $source, $occurrences);

        $this->assertCount(
            3,
            $occurrences[0],
            "PackageEntitlementService should reference 'used_quantity' in exactly three places — activation (0), "
            .'consumeForLesson() (+1), and the audit metadata read. A fourth is a second balance-mutation path.',
        );

        $this->assertStringContainsString('function consumeForLesson(', $source);
    }

    public function test_no_application_code_increments_the_balance_directly(): void
    {
        // The dangerous shapes, in the forms a developer would actually
        // reach for. Eloquent's increment()/decrement() bypass the row
        // lock, the ledger and the reservation entirely, and a mass
        // update() would bypass them silently across many rows.
        $forbidden = [
            "increment('used_quantity'",
            'increment("used_quantity"',
            "decrement('used_quantity'",
            'decrement("used_quantity"',
            '->used_quantity++',
            '->used_quantity +=',
            '->used_quantity--',
        ];

        $offenders = [];

        foreach ($this->applicationFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = basename($file).' → '.$pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Package balance may only change inside PackageEntitlementService::consumeForLesson(), which writes '
            ."the consumption ledger and claims the reservation in the same transaction. Found: \n"
            .implode("\n", $offenders),
        );
    }

    public function test_no_code_outside_the_package_service_writes_used_quantity(): void
    {
        $servicePath = (new ReflectionClass(PackageEntitlementService::class))->getFileName();
        $offenders = [];

        foreach ($this->applicationFiles() as $file) {
            if ($file === $servicePath) {
                continue;
            }

            // The model itself necessarily NAMES the column — in
            // $attributes defaults, $fillable, $casts and the activity-log
            // config. Those are declarations, not writes, and the model
            // has no method that mutates the balance.
            if (str_ends_with($file, 'StudentPackageEntitlement.php')) {
                continue;
            }

            $contents = php_strip_whitespace($file);

            // Comments are stripped first: several files legitimately
            // DISCUSS used_quantity (the listeners explain that they
            // deliberately do not touch it), and punishing the
            // explanation would push the reasoning out of the code.
            if (str_contains($contents, "'used_quantity' =>") || str_contains($contents, '"used_quantity" =>')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'Only PackageEntitlementService may write used_quantity. Found: '.implode(', ', $offenders));
    }

    public function test_the_consumption_path_still_writes_the_ledger_and_claims_the_reservation(): void
    {
        $source = $this->entitlementServiceSource();

        $start = strpos($source, 'function consumeForLesson(');
        $this->assertNotFalse($start, 'The canonical consumption path is missing.');

        $body = substr($source, $start);

        // The three things that must happen together. Asserting their
        // presence stops a future "simplification" from quietly reducing
        // consumption back to a bare counter increment.
        $this->assertStringContainsString('StudentPackageEntitlementConsumption::query()->create(', $body, 'Consumption must write the immutable ledger row.');
        $this->assertStringContainsString('consumeReservationFor(', $body, 'Consumption must claim the booking’s reservation.');
        $this->assertStringContainsString('lockForUpdate()', $body, 'Consumption must run under the entitlement row lock.');
    }
}
