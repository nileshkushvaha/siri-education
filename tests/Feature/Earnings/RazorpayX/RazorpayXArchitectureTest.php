<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use Tests\TestCase;

/**
 * Structural guarantees specific to the RazorpayX adapter, on top of
 * the provider-neutral architecture proven in
 * FinancialArchitectureTest.
 */
class RazorpayXArchitectureTest extends TestCase
{
    public function test_no_razorpayx_file_ever_logs_a_raw_provider_payload_or_secret(): void
    {
        foreach ($this->phpFilesIn([app_path('Earnings/Providers/RazorpayX')]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                '/Log::\w+\([^)]*(payload|response|body|secret|key_secret|webhook_secret)/i',
                $code,
                "Possible raw payload/secret logging in {$path}",
            );
        }
    }

    /** Decrypted bank details must never leave the provisioning service's own method scope into a queued job payload. */
    public function test_no_job_class_references_the_fund_account_request_dto_or_payout_method_details(): void
    {
        foreach ($this->phpFilesIn([app_path('Jobs')]) as $path => $code) {
            $this->assertStringNotContainsString('RazorpayXFundAccountRequest', $code, $path);
            $this->assertStringNotContainsString('PayoutMethodDetails', $code, $path);
        }
    }

    public function test_provisioning_service_never_calls_the_client_from_inside_a_locked_claim_transaction(): void
    {
        $code = file_get_contents(app_path('Earnings/Providers/RazorpayX/RazorpayXDestinationProvisioningService.php'));

        // Every DB::transaction(...) closure in this file is the
        // "claim" step only — it may read/write the link row, but must
        // never itself call $this->client->*, since that would put a
        // real network call inside a database transaction/lock.
        preg_match_all('/DB::transaction\(function \(\).*?\}, attempts: 3\);/s', $code, $matches);

        $this->assertNotEmpty($matches[0], 'Expected to find the claim transactions in the provisioning service.');

        foreach ($matches[0] as $transactionBody) {
            $this->assertDoesNotMatchRegularExpression('/\$this->client->/', $transactionBody);
        }
    }

    public function test_no_hardcoded_razorpay_api_base_url_outside_the_http_client(): void
    {
        $allowedFile = app_path('Earnings/Providers/RazorpayX/RazorpayXHttpPayoutClient.php');

        foreach ($this->phpFilesIn([app_path('Earnings')]) as $path => $code) {
            if ($path === $allowedFile) {
                continue;
            }

            $this->assertStringNotContainsString('api.razorpay.com', $code, "Unexpected hardcoded RazorpayX base URL in {$path}");
        }
    }

    /** The RazorpayX webhook route must be the only public route this phase adds under /api/webhooks/payouts. */
    public function test_only_the_razorpayx_webhook_route_exists_under_the_payouts_webhook_prefix(): void
    {
        $matching = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/webhooks/payouts'));

        $this->assertCount(1, $matching);
        $this->assertSame('api/webhooks/payouts/razorpayx', $matching->first()->uri());
    }

    /** @return array<string, string> */
    private function phpFilesIn(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
