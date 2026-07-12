<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXContactRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXContactResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXFundAccountRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXFundAccountResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXHealthResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutResult;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutClientInterface;

/**
 * A deterministic, network-free double for the real-MySQL concurrency
 * harness (tests/Concurrency/run-op.php) — child worker processes
 * cannot share a Mockery instance across process boundaries, so this
 * fills the same role the fake payout provider fills for the generic
 * execution races. Every "provider" identifier is freshly minted per
 * call; the concurrency property under test is that the DATABASE layer
 * (unique constraint + row lock) allows only one link row to ever
 * reach a terminal Contact/Fund Account, never this client's behavior.
 */
final class RazorpayXConcurrencyFakeClient implements RazorpayXPayoutClientInterface
{
    public function createContact(RazorpayXContactRequest $request): RazorpayXContactResult
    {
        return new RazorpayXContactResult('cont_'.bin2hex(random_bytes(6)), 'active', $request->referenceId);
    }

    public function fetchContact(string $contactId): RazorpayXContactResult
    {
        return new RazorpayXContactResult($contactId, 'active', null);
    }

    public function findContactsByReference(string $referenceId): array
    {
        return [];
    }

    public function createBankFundAccount(RazorpayXFundAccountRequest $request): RazorpayXFundAccountResult
    {
        return new RazorpayXFundAccountResult('fa_'.bin2hex(random_bytes(6)), $request->contactId, 'active');
    }

    public function fetchFundAccount(string $fundAccountId): RazorpayXFundAccountResult
    {
        return new RazorpayXFundAccountResult($fundAccountId, 'cont_unknown', 'active');
    }

    public function fetchFundAccountsForContact(string $contactId): array
    {
        return [];
    }

    public function createPayout(RazorpayXPayoutRequest $request): RazorpayXPayoutResult
    {
        return new RazorpayXPayoutResult('pout_'.bin2hex(random_bytes(6)), 'processing', null, null, null, $request->mode, $request->referenceId);
    }

    public function fetchPayout(string $payoutId): RazorpayXPayoutResult
    {
        return new RazorpayXPayoutResult($payoutId, 'processing', null, null, null, 'IMPS', null);
    }

    public function cancelQueuedPayout(string $payoutId): bool
    {
        return true;
    }

    public function fetchBalanceOrHealth(string $accountNumber): RazorpayXHealthResult
    {
        return new RazorpayXHealthResult(healthy: true);
    }
}
