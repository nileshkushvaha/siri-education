<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXContactRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXContactResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXFundAccountRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXFundAccountResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXHealthResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutResult;

/**
 * The low-level RazorpayX API boundary. Every network call this phase
 * makes goes through exactly one implementation of this interface
 * (`RazorpayXHttpPayoutClient`) — nothing above it ever issues an HTTP
 * request directly, touches credentials, or sees a raw RazorpayX
 * response shape. Every RazorpayX test in this codebase mocks this
 * contract directly (`Mockery::mock(RazorpayXPayoutClientInterface::class)`),
 * so `RazorpayXHttpPayoutClient` — the only concrete implementation —
 * is never instantiated by a test and can never reach the real
 * RazorpayX API.
 *
 * Transport note (audited, §8 of the phase spec): the installed
 * `razorpay/razorpay` SDK (2.9.3) exposes no `Contact` or `Payout`
 * resource class at all — only `FundAccount`. Rather than split
 * transport inconsistently (SDK for one RazorpayX operation, raw HTTP
 * for the other two that touch the exact same product), every
 * operation here uses direct, authenticated HTTP calls against the
 * documented RazorpayX REST API — the same Basic-Auth + JSON scheme
 * the SDK itself uses internally.
 */
interface RazorpayXPayoutClientInterface
{
    /** @throws RazorpayXRequestException */
    public function createContact(RazorpayXContactRequest $request): RazorpayXContactResult;

    /** @throws RazorpayXRequestException */
    public function fetchContact(string $contactId): RazorpayXContactResult;

    /** @return list<RazorpayXContactResult> @throws RazorpayXRequestException */
    public function findContactsByReference(string $referenceId): array;

    /** @throws RazorpayXRequestException */
    public function createBankFundAccount(RazorpayXFundAccountRequest $request): RazorpayXFundAccountResult;

    /** @throws RazorpayXRequestException */
    public function fetchFundAccount(string $fundAccountId): RazorpayXFundAccountResult;

    /** @return list<RazorpayXFundAccountResult> @throws RazorpayXRequestException */
    public function fetchFundAccountsForContact(string $contactId): array;

    /** @throws RazorpayXRequestException */
    public function createPayout(RazorpayXPayoutRequest $request): RazorpayXPayoutResult;

    /** @throws RazorpayXRequestException */
    public function fetchPayout(string $payoutId): RazorpayXPayoutResult;

    /** True if cancelled, false if the provider does not support cancelling this payout (e.g. no longer queued). @throws RazorpayXRequestException */
    public function cancelQueuedPayout(string $payoutId): bool;

    /** Never throws on a reachability failure — returns an unhealthy result instead, for use by readiness/health checks. */
    public function fetchBalanceOrHealth(string $accountNumber): RazorpayXHealthResult;
}
