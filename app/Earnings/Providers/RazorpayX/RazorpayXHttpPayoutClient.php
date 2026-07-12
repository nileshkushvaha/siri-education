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
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only class in this codebase that ever sends a RazorpayX HTTP
 * request. See `RazorpayXPayoutClientInterface`'s docblock for why
 * this uses direct HTTP rather than the `razorpay/razorpay` SDK.
 */
final class RazorpayXHttpPayoutClient implements RazorpayXPayoutClientInterface
{
    private const string BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private readonly RazorpayXPayoutSettings $settings,
    ) {}

    public function createContact(RazorpayXContactRequest $request): RazorpayXContactResult
    {
        $response = $this->request()->post('/contacts', array_filter([
            'name' => $request->name,
            'type' => $request->type,
            'reference_id' => $request->referenceId,
            'email' => $request->email,
            'contact' => $request->contact,
        ], fn ($v) => $v !== null));

        $body = $this->decode($response, 'createContact');

        return new RazorpayXContactResult((string) $body['id'], (string) ($body['active'] ?? true ? 'active' : 'inactive'), $body['reference_id'] ?? null);
    }

    public function fetchContact(string $contactId): RazorpayXContactResult
    {
        $response = $this->request()->get("/contacts/{$contactId}");
        $body = $this->decode($response, 'fetchContact');

        return new RazorpayXContactResult((string) $body['id'], (string) (($body['active'] ?? true) ? 'active' : 'inactive'), $body['reference_id'] ?? null);
    }

    public function findContactsByReference(string $referenceId): array
    {
        $response = $this->request()->get('/contacts', ['reference_id' => $referenceId]);
        $body = $this->decode($response, 'findContactsByReference');

        return array_map(
            fn (array $item): RazorpayXContactResult => new RazorpayXContactResult((string) $item['id'], (string) (($item['active'] ?? true) ? 'active' : 'inactive'), $item['reference_id'] ?? null),
            $body['items'] ?? [],
        );
    }

    public function createBankFundAccount(RazorpayXFundAccountRequest $request): RazorpayXFundAccountResult
    {
        $response = $this->request()->post('/fund_accounts', [
            'contact_id' => $request->contactId,
            'account_type' => 'bank_account',
            'bank_account' => [
                'name' => $request->accountHolderName,
                'ifsc' => $request->ifsc,
                'account_number' => $request->accountNumber,
            ],
        ]);

        $body = $this->decode($response, 'createBankFundAccount');

        return new RazorpayXFundAccountResult((string) $body['id'], (string) $body['contact_id'], (string) ($body['active'] ?? true ? 'active' : 'inactive'));
    }

    public function fetchFundAccount(string $fundAccountId): RazorpayXFundAccountResult
    {
        $response = $this->request()->get("/fund_accounts/{$fundAccountId}");
        $body = $this->decode($response, 'fetchFundAccount');

        return new RazorpayXFundAccountResult((string) $body['id'], (string) $body['contact_id'], (string) (($body['active'] ?? true) ? 'active' : 'inactive'));
    }

    public function fetchFundAccountsForContact(string $contactId): array
    {
        $response = $this->request()->get('/fund_accounts', ['contact_id' => $contactId]);
        $body = $this->decode($response, 'fetchFundAccountsForContact');

        return array_map(
            fn (array $item): RazorpayXFundAccountResult => new RazorpayXFundAccountResult((string) $item['id'], (string) $item['contact_id'], (string) (($item['active'] ?? true) ? 'active' : 'inactive')),
            $body['items'] ?? [],
        );
    }

    public function createPayout(RazorpayXPayoutRequest $request): RazorpayXPayoutResult
    {
        $response = $this->request()
            ->withHeader('X-Payout-Idempotency', $request->idempotencyKey)
            ->post('/payouts', [
                'account_number' => $request->accountNumber,
                'fund_account_id' => $request->fundAccountId,
                'amount' => $request->amountMinor,
                'currency' => 'INR',
                'mode' => $request->mode,
                'purpose' => $request->purpose,
                'queue_if_low_balance' => $request->queueIfLowBalance,
                'reference_id' => $request->referenceId,
                'narration' => Str::limit($request->narration, 30, ''),
            ]);

        return $this->payoutResultFrom($this->decode($response, 'createPayout'));
    }

    public function fetchPayout(string $payoutId): RazorpayXPayoutResult
    {
        $response = $this->request()->get("/payouts/{$payoutId}");

        return $this->payoutResultFrom($this->decode($response, 'fetchPayout'));
    }

    public function cancelQueuedPayout(string $payoutId): bool
    {
        $response = $this->request()->post("/payouts/{$payoutId}/cancel");

        if ($response->status() === 400) {
            // Already accepted for processing — cancellation is no longer possible; not an error.
            return false;
        }

        $this->decode($response, 'cancelQueuedPayout');

        return true;
    }

    public function fetchBalanceOrHealth(string $accountNumber): RazorpayXHealthResult
    {
        try {
            $response = $this->request()->get('/payouts', ['account_number' => $accountNumber, 'count' => 1]);

            if ($response->successful()) {
                return new RazorpayXHealthResult(healthy: true);
            }

            return new RazorpayXHealthResult(healthy: false, safeMessage: 'RazorpayX responded with an error status.');
        } catch (Throwable) {
            return new RazorpayXHealthResult(healthy: false, safeMessage: 'RazorpayX could not be reached.');
        }
    }

    // ── Internals ─────────────────────────────────────────────────────

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth((string) $this->settings->razorpayx_key_id, $this->decryptedSecret())
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    private function decryptedSecret(): string
    {
        $value = $this->settings->razorpayx_key_secret;

        if (blank($value)) {
            throw new RazorpayXRequestException('RazorpayX key_secret is not configured.');
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $operation): array
    {
        try {
            $body = $response->json();
        } catch (Throwable $e) {
            throw new RazorpayXRequestException("RazorpayX {$operation} returned a non-JSON response.", httpStatus: $response->status(), previous: $e);
        }

        if ($response->failed()) {
            $error = is_array($body) ? ($body['error'] ?? []) : [];

            throw new RazorpayXRequestException(
                is_array($error) ? (string) ($error['description'] ?? 'RazorpayX request failed.') : 'RazorpayX request failed.',
                razorpayErrorCode: is_array($error) ? ($error['code'] ?? null) : null,
                httpStatus: $response->status(),
            );
        }

        if (! is_array($body)) {
            throw new RazorpayXRequestException("RazorpayX {$operation} returned an unexpected response shape.", httpStatus: $response->status());
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function payoutResultFrom(array $body): RazorpayXPayoutResult
    {
        return new RazorpayXPayoutResult(
            payoutId: (string) $body['id'],
            status: (string) $body['status'],
            utr: $body['utr'] ?? null,
            feesMinor: isset($body['fees']) ? (int) $body['fees'] : null,
            taxMinor: isset($body['tax']) ? (int) $body['tax'] : null,
            mode: (string) ($body['mode'] ?? ''),
            referenceId: $body['reference_id'] ?? null,
            failureReason: $body['failure_reason'] ?? null,
        );
    }
}
