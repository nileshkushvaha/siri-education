<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditTrailService;
use App\Settings\GeneralSettings;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wallet lifecycle only — creation and status transitions. All balance
 * mutation lives in WalletLedgerService; nothing here ever writes to a
 * *_minor column directly.
 */
final class WalletService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
        private readonly GeneralSettings $generalSettings,
    ) {}

    /**
     * Find or atomically create the user's wallet in the given currency
     * (or their resolved default currency). Safe to call concurrently —
     * the DB's unique(user_id, currency_id) index is the final guard
     * against a duplicate wallet, not just this method's own check.
     */
    public function getOrCreateWallet(User $user, ?string $currencyCode = null, ?User $actor = null): Wallet
    {
        $actor ??= $user;

        if ($actor->id !== $user->id && ! $actor->can('manage', Wallet::class)) {
            throw new AuthorizationException;
        }

        $currency = $this->resolveCurrency($user, $currencyCode);

        $existing = Wallet::query()->forUser($user->id)->where('currency_id', $currency->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $currency, $actor): Wallet {
                $wallet = Wallet::query()->create([
                    'user_id' => $user->id,
                    'currency_id' => $currency->id,
                    'currency_code' => $currency->code,
                    'balance_minor' => 0,
                    'available_balance_minor' => 0,
                    'held_balance_minor' => 0,
                    'status' => WalletStatus::Active,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $this->auditTrail->logUser(
                    $actor,
                    'wallets',
                    'created',
                    sprintf('Wallet created for user #%d in %s.', $user->id, $currency->code),
                    $wallet,
                    ['user_id' => $user->id, 'currency_code' => $currency->code],
                );

                return $wallet;
            });
        } catch (QueryException) {
            // Lost the race to a concurrent create — the unique index
            // already prevented the duplicate; fetch the winner instead.
            return Wallet::query()->forUser($user->id)->where('currency_id', $currency->id)->firstOrFail();
        }
    }

    public function freeze(Wallet $wallet, User $actor, string $reason): Wallet
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($wallet, $actor, $reason): Wallet {
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $previous = $wallet->status;

            $wallet->forceFill(['status' => WalletStatus::Frozen, 'updated_by' => $actor->id])->save();

            $this->auditTrail->logOverride(
                $actor,
                'wallets',
                'frozen',
                sprintf('Wallet %s frozen.', $wallet->id),
                $reason,
                $wallet,
                ['previous_status' => $previous->value, 'new_status' => WalletStatus::Frozen->value],
            );

            return $wallet->refresh();
        });
    }

    public function unfreeze(Wallet $wallet, User $actor, string $reason): Wallet
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($wallet, $actor, $reason): Wallet {
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            if ($wallet->status !== WalletStatus::Frozen) {
                throw new WalletException(sprintf('Wallet %s is not frozen.', $wallet->id));
            }

            $wallet->forceFill(['status' => WalletStatus::Active, 'updated_by' => $actor->id])->save();

            $this->auditTrail->logOverride(
                $actor,
                'wallets',
                'unfrozen',
                sprintf('Wallet %s unfrozen.', $wallet->id),
                $reason,
                $wallet,
                ['previous_status' => WalletStatus::Frozen->value, 'new_status' => WalletStatus::Active->value],
            );

            return $wallet->refresh();
        });
    }

    public function close(Wallet $wallet, User $actor, string $reason): Wallet
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($wallet, $actor, $reason): Wallet {
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $previous = $wallet->status;

            $wallet->forceFill(['status' => WalletStatus::Closed, 'updated_by' => $actor->id])->save();

            $this->auditTrail->logOverride(
                $actor,
                'wallets',
                'closed',
                sprintf('Wallet %s closed.', $wallet->id),
                $reason,
                $wallet,
                ['previous_status' => $previous->value, 'new_status' => WalletStatus::Closed->value],
            );

            return $wallet->refresh();
        });
    }

    /**
     * Instructors may only manage their own wallet is intentionally NOT a
     * rule here — Phase 9 exposes wallets to students read-only and to
     * admins for management. Only an actor with the Shield permission
     * (or super_admin, via Gate::before()) may freeze/unfreeze/close.
     */
    private function assertCanManage(User $actor): void
    {
        if (! $actor->can('manage', Wallet::class)) {
            throw new AuthorizationException;
        }
    }

    private function resolveCurrency(User $user, ?string $currencyCode): Currency
    {
        $code = $currencyCode
            ?? $user->profile?->country?->defaultCurrency?->code
            ?? $this->generalSettings->default_currency;

        $currency = Currency::query()->active()->where('code', $code)->first();

        if ($currency === null) {
            throw ValidationException::withMessages(['currency' => "Currency \"{$code}\" is not active."]);
        }

        return $currency;
    }
}
