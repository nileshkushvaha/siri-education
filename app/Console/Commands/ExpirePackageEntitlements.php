<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Package\Services\PackageEntitlementService;
use Illuminate\Console\Command;

/**
 * Housekeeping sweep for lapsed package entitlements.
 *
 * Deliberately NOT load-bearing: expiry is enforced synchronously by
 * PackageEntitlementService::usable()/consumeLesson(), so an entitlement
 * is unusable the instant it lapses whether or not this has run. The
 * sweep only keeps admin lists and dashboards honest for entitlements
 * nobody has read since they expired.
 *
 * Idempotent by construction — it reuses the same expireIfNeeded()
 * transition, which no-ops on anything already terminal.
 */
final class ExpirePackageEntitlements extends Command
{
    protected $signature = 'package-entitlements:expire {--limit=500}';

    protected $description = 'Mark lapsed package entitlements as expired so listings stay accurate.';

    public function handle(PackageEntitlementService $entitlements): int
    {
        $expired = $entitlements->expireDue((int) $this->option('limit'));

        $this->info("Expired {$expired} lapsed package entitlement(s).");

        return self::SUCCESS;
    }
}
