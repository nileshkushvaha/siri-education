<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\DTOs\AccountWalletSummaryData;
use App\Enums\PortalAudience;
use App\Models\User;
use App\Models\Wallet;
use App\Settings\FeatureSettings;
use App\Wallet\Support\WalletMoneyFormatter;

final class AccountWalletSummaryService
{
    public function __construct(
        private readonly FeatureSettings $features,
    ) {}

    public function enabledFor(PortalAudience $audience): bool
    {
        return $audience === PortalAudience::Student && $this->features->wallet_enabled;
    }

    public function referralEnabledFor(PortalAudience $audience): bool
    {
        return $audience === PortalAudience::Student && $this->features->referral_enabled;
    }

    public function for(User $user, PortalAudience $audience): ?AccountWalletSummaryData
    {
        if (! $this->enabledFor($audience)) {
            return null;
        }

        $wallet = Wallet::query()
            ->forUser($user->id)
            ->with('currency')
            ->orderBy('currency_code')
            ->first();

        if ($wallet === null) {
            return null;
        }

        return new AccountWalletSummaryData(
            availableBalance: WalletMoneyFormatter::format(
                $wallet->available_balance_minor,
                $wallet->currency,
                $wallet->currency_code,
            ),
            currencyCode: $wallet->currency_code,
        );
    }
}
