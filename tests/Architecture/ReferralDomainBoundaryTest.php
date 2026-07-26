<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Permanent guards for the Referral domain boundary.
 *
 * The Version 1 referral program moves money only through the Wallet
 * ledger service — never Earnings, gateways, or payment providers
 * directly. Its lesson-outcome listeners stay thin triggers (no money
 * math, no ledger writes) and the domain exposes no public
 * code-validation surface that could become an account-enumeration
 * oracle. Registration integrates through exactly one approved point
 * (RegistrationService); the frontends never attribute directly.
 */
class ReferralDomainBoundaryTest extends TestCase
{
    // ── Referral money moves ONLY through the wallet ledger service ──────

    public function test_referral_domain_touches_money_only_through_approved_wallet_services(): void
    {
        // The reward service legitimately uses the wallet domain's
        // public services and the lesson/booking read surface. It must
        // never depend on Earnings, gateways, or providers.
        foreach ($this->phpFilesUnder(base_path('app/Referral')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                'App\Earnings\\',
                'App\Booking\Gateways', 'App\Booking\Payments\\',
                'PaymentProviderRegistry', 'StripeGatewayClient', 'RazorpayX',
                'Http::', 'GuzzleHttp', 'curl_',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never depend on earnings or payment providers (found \"{$needle}\").");
            }

            // No direct ledger/balance writes anywhere in the domain.
            foreach ([
                'WalletLedgerEntry::query()->create', 'WalletLedgerEntry::create',
                "DB::table('wallet", 'balance_minor =', "->forceFill(['balance",
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never write the ledger or balances directly (found \"{$needle}\").");
            }
        }
    }

    public function test_single_calculation_owner_and_thin_listeners(): void
    {
        // Exactly one file in the entire app performs the reward math.
        $calculationOwners = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            if (str_contains((string) file_get_contents($file), 'intdiv($lessonAmountMinor')) {
                $calculationOwners[] = $file;
            }
        }

        $this->assertSame([base_path('app/Referral/Services/ReferralRewardService.php')], $calculationOwners);

        // The four listeners exist, live in the domain, and stay thin —
        // no money math, no ledger service, no direct writes.
        $listeners = $this->phpFilesUnder(base_path('app/Referral/Listeners'));
        $this->assertCount(4, $listeners);

        foreach ($listeners as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['intdiv', 'WalletLedgerService', '::create(', '->save(', '->forceFill('] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must stay a thin trigger (found \"{$needle}\").");
            }
        }

        // The global listener directory still carries no referral logic.
        foreach ($this->phpFilesUnder(base_path('app/Listeners')) as $file) {
            $this->assertStringNotContainsString('Referral', basename($file));
        }
    }

    public function test_reward_and_campaign_models_exist_but_no_instructor_referral_path(): void
    {
        $this->assertTrue(class_exists('App\Models\ReferralCampaign'));
        $this->assertTrue(class_exists('App\Models\ReferralReward'));

        foreach ($this->phpFilesUnder(base_path('app/Referral')) as $file) {
            $contents = strtolower((string) file_get_contents($file));

            foreach (['instructor referral', 'affiliate', 'parent referral', 'corporate referral'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must not introduce non-student referral programs.");
            }
        }
    }

    // ── One approved integration point ───────────────────────────────────

    public function test_only_registration_service_calls_the_attribution_service(): void
    {
        $callers = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            if (str_starts_with($file, base_path('app/Referral')) || str_starts_with($file, base_path('app/Providers'))) {
                continue;
            }

            if (str_contains((string) file_get_contents($file), 'ReferralAttributionServiceInterface')) {
                $callers[] = $file;
            }
        }

        sort($callers);

        // Exactly one more approved surface exists beyond RegistrationService:
        // the admin correction action. It may only ever call
        // correctAttribution — never attributeFromRegistration, which
        // stays exclusive to RegistrationService.
        $this->assertSame(
            [
                base_path('app/Filament/Resources/ReferralAttributions/Tables/ReferralAttributionsTable.php'),
                base_path('app/Services/Auth/RegistrationService.php'),
            ],
            $callers,
            'Only RegistrationService (attribution) and the admin correction table may call the attribution service.',
        );

        $adminTable = (string) file_get_contents(base_path('app/Filament/Resources/ReferralAttributions/Tables/ReferralAttributionsTable.php'));
        $this->assertStringNotContainsString('attributeFromRegistration', $adminTable);
    }

    public function test_registration_frontends_do_not_create_attributions_directly(): void
    {
        foreach ([
            base_path('app/Livewire/Frontend/Auth/RegisterForm.php'),
            base_path('app/Http/Controllers/Auth/RegisterController.php'),
        ] as $file) {
            $contents = (string) file_get_contents($file);

            // The frontends carry the raw string through to
            // RegistrationService — they must never import the Referral
            // domain or its models to look up, validate, or attribute.
            $this->assertStringNotContainsString('App\Referral\\', $contents, "{$file} must delegate attribution to RegistrationService only.");
            $this->assertStringNotContainsString('App\Models\ReferralCode', $contents, "{$file} must never look up or validate referral codes itself.");
            $this->assertStringNotContainsString('App\Models\ReferralAttribution', $contents, "{$file} must never create attributions itself.");
        }
    }

    // ── No enumeration surface ───────────────────────────────────────────

    public function test_no_public_code_validation_or_lookup_route_exists(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsString('referral-code', $routes);
        $this->assertStringNotContainsString('validate-referral', $routes);
        $this->assertStringNotContainsString('referral/check', $routes);

        // The only referral route is the authenticated student page.
        $this->assertSame(1, substr_count($routes, "'/refer-a-friend'"));
    }

    // ── Events carry identifiers, not models ─────────────────────────────

    public function test_referral_events_expose_no_models(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Referral/Events')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('App\Models\\', $contents, "{$file} must carry stable identifiers, never models.");
            $this->assertStringNotContainsString('SerializesModels', $contents, "{$file} must not serialize models.");
        }
    }

    // ── No duplicate feature switch, no float money ──────────────────────

    public function test_no_second_referral_feature_switch_exists(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Referral')) as $file) {
            $this->assertStringNotContainsString('referral.enabled', (string) file_get_contents($file), 'FeatureSettings::$referral_enabled is the single referral switch.');
        }

        // ReferralSettings is retired entirely — referral_campaigns is
        // the single source of reward rules and FeatureSettings the
        // single switch. The class must never come back.
        $this->assertFalse(class_exists('App\Settings\ReferralSettings'));
    }

    public function test_referral_domain_introduces_no_float_money_fields(): void
    {
        foreach (['app/Referral', 'app/Models/ReferralCode.php', 'app/Models/ReferralAttribution.php', 'app/Models/ReferralCampaign.php', 'app/Models/ReferralReward.php'] as $path) {
            $files = is_dir(base_path($path)) ? $this->phpFilesUnder(base_path($path)) : [base_path($path)];

            foreach ($files as $file) {
                $contents = (string) file_get_contents($file);

                // Real float/decimal declarations only — prose in docblocks
                // ("never floats") must not trip the guard.
                foreach (['float $', ': float', '(float)', "'float'", "'decimal", '->decimal(', '->float('] as $needle) {
                    $this->assertStringNotContainsString($needle, $contents, "{$file} must not introduce float/decimal money fields — money is always integer minor units (found \"{$needle}\").");
                }
            }
        }
    }

    // ── Source domains do not depend on Referral ─────────────────────────

    public function test_no_source_domain_imports_the_referral_namespace(): void
    {
        foreach (['app/Booking', 'app/Lessons', 'app/Wallet', 'app/Earnings', 'app/Reviews', 'app/Quality', 'app/Reporting'] as $domain) {
            foreach ($this->phpFilesUnder(base_path($domain)) as $file) {
                $this->assertStringNotContainsString(
                    'App\Referral\\',
                    (string) file_get_contents($file),
                    "{$file} in {$domain} must not depend on App\\Referral.",
                );
            }
        }
    }

    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
