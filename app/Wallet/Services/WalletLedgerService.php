<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Compliance\Rules\UnusualManualWalletAdjustmentsRule;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\AuditTrailService;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\InsufficientBalanceException;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Exceptions\WalletNotUsableException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only place a wallet's *_minor columns are ever written. Every
 * mutation: (1) locks the wallet row (`lockForUpdate()`), (2) re-checks
 * idempotency under that lock, (3) validates wallet usability and
 * sufficient balance, (4) writes one append-only ledger row carrying
 * the post-mutation balances, (5) updates the wallet's cached totals in
 * the same transaction (inside Wallet::withAuthorizedBalanceMutation(),
 * the only thing that lets the *_minor columns actually save), (6)
 * audit-logs through AuditTrailService.
 *
 * All amounts are integer minor units (paise/cents) — never float.
 * `balance_minor === available_balance_minor + held_balance_minor`
 * is enforced by both this service's arithmetic and a DB CHECK
 * constraint, so the two can never drift.
 */
final class WalletLedgerService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
        private readonly UnusualManualWalletAdjustmentsRule $complianceRule,
        private readonly ComplianceMonitoringService $compliance,
    ) {}

    public function credit(
        Wallet $wallet,
        int $amountMinor,
        WalletLedgerEntryType $type,
        User $actor,
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        array $metadata = [],
    ): WalletLedgerEntry {
        $this->assertPositiveAmount($amountMinor);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey)) !== null) {
            return $existing;
        }

        return $this->transactional(function () use ($wallet, $amountMinor, $type, $actor, $idempotencyKey, $description, $sourceType, $sourceId, $metadata): WalletLedgerEntry {
            $wallet = $this->lockedWallet($wallet->id);

            if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey)) !== null) {
                return $existing;
            }

            $this->assertUsable($wallet, forDebit: false);

            $balanceAfter = $wallet->balance_minor + $amountMinor;
            $availableAfter = $wallet->available_balance_minor + $amountMinor;
            $heldAfter = $wallet->held_balance_minor;

            $entry = $this->postEntry(
                $wallet, WalletLedgerDirection::Credit, $type, $amountMinor,
                $balanceAfter, $availableAfter, $heldAfter,
                $actor, $idempotencyKey, $description, $sourceType, $sourceId, $metadata,
            );

            $wallet->forceFill([
                'balance_minor' => $balanceAfter,
                'available_balance_minor' => $availableAfter,
                'updated_by' => $actor->id,
            ])->save();

            $this->logEntry($actor, $entry, 'credited');

            return $entry;
        });
    }

    public function debit(
        Wallet $wallet,
        int $amountMinor,
        WalletLedgerEntryType $type,
        User $actor,
        ?string $idempotencyKey = null,
        ?string $description = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        array $metadata = [],
    ): WalletLedgerEntry {
        $this->assertPositiveAmount($amountMinor);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey)) !== null) {
            return $existing;
        }

        return $this->transactional(function () use ($wallet, $amountMinor, $type, $actor, $idempotencyKey, $description, $sourceType, $sourceId, $metadata): WalletLedgerEntry {
            $wallet = $this->lockedWallet($wallet->id);

            if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey)) !== null) {
                return $existing;
            }

            $this->assertUsable($wallet, forDebit: true);

            if ($wallet->available_balance_minor < $amountMinor) {
                throw InsufficientBalanceException::forDebit($wallet->id, $amountMinor, $wallet->available_balance_minor);
            }

            $balanceAfter = $wallet->balance_minor - $amountMinor;
            $availableAfter = $wallet->available_balance_minor - $amountMinor;
            $heldAfter = $wallet->held_balance_minor;

            $entry = $this->postEntry(
                $wallet, WalletLedgerDirection::Debit, $type, $amountMinor,
                $balanceAfter, $availableAfter, $heldAfter,
                $actor, $idempotencyKey, $description, $sourceType, $sourceId, $metadata,
            );

            $wallet->forceFill([
                'balance_minor' => $balanceAfter,
                'available_balance_minor' => $availableAfter,
                'updated_by' => $actor->id,
            ])->save();

            $this->logEntry($actor, $entry, 'debited');

            return $entry;
        });
    }

    /** Moves money from available -> held. Total balance is unchanged. */
    public function placeHold(Wallet $wallet, int $amountMinor, User $actor, ?string $sourceType = null, ?string $sourceId = null, ?string $description = null): WalletLedgerEntry
    {
        $this->assertPositiveAmount($amountMinor);

        return $this->transactional(function () use ($wallet, $amountMinor, $actor, $sourceType, $sourceId, $description): WalletLedgerEntry {
            $wallet = $this->lockedWallet($wallet->id);
            $this->assertUsable($wallet, forDebit: true);

            if ($wallet->available_balance_minor < $amountMinor) {
                throw InsufficientBalanceException::forDebit($wallet->id, $amountMinor, $wallet->available_balance_minor);
            }

            $availableAfter = $wallet->available_balance_minor - $amountMinor;
            $heldAfter = $wallet->held_balance_minor + $amountMinor;

            $entry = $this->postEntry(
                $wallet, WalletLedgerDirection::Debit, WalletLedgerEntryType::BookingHold, $amountMinor,
                $wallet->balance_minor, $availableAfter, $heldAfter,
                $actor, null, $description, $sourceType, $sourceId, [],
            );

            $wallet->forceFill([
                'available_balance_minor' => $availableAfter,
                'held_balance_minor' => $heldAfter,
                'updated_by' => $actor->id,
            ])->save();

            $this->logEntry($actor, $entry, 'hold placed');

            return $entry;
        });
    }

    /** Moves money from held -> available. Total balance is unchanged. */
    public function releaseHold(Wallet $wallet, int $amountMinor, User $actor, ?string $sourceType = null, ?string $sourceId = null, ?string $description = null): WalletLedgerEntry
    {
        $this->assertPositiveAmount($amountMinor);

        return $this->transactional(function () use ($wallet, $amountMinor, $actor, $sourceType, $sourceId, $description): WalletLedgerEntry {
            $wallet = $this->lockedWallet($wallet->id);

            if ($wallet->held_balance_minor < $amountMinor) {
                throw new WalletException(sprintf('Wallet %s does not have %d held to release.', $wallet->id, $amountMinor));
            }

            $availableAfter = $wallet->available_balance_minor + $amountMinor;
            $heldAfter = $wallet->held_balance_minor - $amountMinor;

            $entry = $this->postEntry(
                $wallet, WalletLedgerDirection::Credit, WalletLedgerEntryType::BookingHoldRelease, $amountMinor,
                $wallet->balance_minor, $availableAfter, $heldAfter,
                $actor, null, $description, $sourceType, $sourceId, [],
            );

            $wallet->forceFill([
                'available_balance_minor' => $availableAfter,
                'held_balance_minor' => $heldAfter,
                'updated_by' => $actor->id,
            ])->save();

            $this->logEntry($actor, $entry, 'hold released');

            return $entry;
        });
    }

    /**
     * The only entry point for a manual balance correction. Requires the
     * `manage` Wallet permission and a non-empty reason — logged via
     * AuditTrailService::logOverride() so it is always independently
     * discoverable as an admin override, regardless of log_name.
     */
    public function adjustment(Wallet $wallet, int $amountMinor, WalletLedgerDirection $direction, User $actor, string $reason): WalletLedgerEntry
    {
        if (! $actor->can('manage', Wallet::class)) {
            throw new AuthorizationException;
        }

        if (trim($reason) === '') {
            throw new WalletException('An admin adjustment requires a reason.');
        }

        $entry = $direction === WalletLedgerDirection::Credit
            ? $this->credit($wallet, $amountMinor, WalletLedgerEntryType::AdminAdjustment, $actor, description: $reason)
            : $this->debit($wallet, $amountMinor, WalletLedgerEntryType::AdminAdjustment, $actor, description: $reason);

        $this->auditTrail->logOverride(
            $actor,
            'wallet_ledger_entries',
            'admin_adjustment',
            sprintf('Admin adjustment (%s) of %d minor units on wallet %s.', $direction->value, $amountMinor, $wallet->id),
            $reason,
            $entry,
            ['direction' => $direction->value, 'amount_minor' => $amountMinor],
        );

        // Compliance monitoring runs strictly after the adjustment has
        // already been posted and audited — a monitoring failure must
        // never roll back or alter this already-committed wallet
        // action, so it is deliberately isolated and only logged.
        try {
            $signal = $this->complianceRule->evaluate($actor);

            if ($signal !== null) {
                $this->compliance->record($signal);
            }
        } catch (Throwable $e) {
            Log::error('Compliance monitoring failed for a wallet admin adjustment.', [
                'wallet_id' => $wallet->id,
                'actor_id' => $actor->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $entry;
    }

    /**
     * Reverses a posted entry with a new offsetting entry — history is
     * never mutated, only the original row's status/reversed_at change.
     *
     * Deliberately does NOT call assertUsable(): reversal is an
     * administrative correction, not a new transaction, and is allowed
     * on a frozen or closed wallet on purpose — those are often exactly
     * the wallets an erroneous entry needs correcting on (e.g. reversing
     * a bad credit that led to the freeze/close in the first place).
     * Already gated behind `manage`, so this is never student-reachable.
     */
    public function reverse(WalletLedgerEntry $entry, User $actor, string $reason): WalletLedgerEntry
    {
        if (! $actor->can('manage', Wallet::class)) {
            throw new AuthorizationException;
        }

        if (trim($reason) === '') {
            throw new WalletException('A reversal requires a reason.');
        }

        if ($entry->status !== WalletLedgerStatus::Posted) {
            throw new WalletException(sprintf('Ledger entry %s is %s and cannot be reversed.', $entry->id, $entry->status->label()));
        }

        return $this->transactional(function () use ($entry, $actor, $reason): WalletLedgerEntry {
            $wallet = $this->lockedWallet($entry->wallet_id);
            $entry = WalletLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->status !== WalletLedgerStatus::Posted) {
                throw new WalletException(sprintf('Ledger entry %s is %s and cannot be reversed.', $entry->id, $entry->status->label()));
            }

            $opposite = $entry->direction->opposite();

            if ($opposite === WalletLedgerDirection::Debit && $wallet->available_balance_minor < $entry->amount_minor) {
                throw InsufficientBalanceException::forDebit($wallet->id, $entry->amount_minor, $wallet->available_balance_minor);
            }

            $sign = $opposite === WalletLedgerDirection::Credit ? 1 : -1;
            $balanceAfter = $wallet->balance_minor + ($sign * $entry->amount_minor);
            $availableAfter = $wallet->available_balance_minor + ($sign * $entry->amount_minor);

            $reversalEntry = $this->postEntry(
                $wallet, $opposite, $entry->entry_type, $entry->amount_minor,
                $balanceAfter, $availableAfter, $wallet->held_balance_minor,
                $actor, null, sprintf('Reversal of %s: %s', $entry->id, $reason), 'wallet_ledger_entry', $entry->id,
                ['reversal_of' => $entry->id],
            );

            $wallet->forceFill([
                'balance_minor' => $balanceAfter,
                'available_balance_minor' => $availableAfter,
                'updated_by' => $actor->id,
            ])->save();

            $entry->forceFill(['status' => WalletLedgerStatus::Reversed, 'reversed_at' => now()])->save();

            $this->auditTrail->logOverride(
                $actor,
                'wallet_ledger_entries',
                'reversed',
                sprintf('Ledger entry %s reversed.', $entry->id),
                $reason,
                $reversalEntry,
                ['original_entry_id' => $entry->id],
            );

            return $reversalEntry;
        });
    }

    public function statement(Wallet $wallet, int $perPage = 15): LengthAwarePaginator
    {
        return WalletLedgerEntry::query()
            ->forWallet($wallet->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /** Every balance-mutating method runs inside this: locked, transactional, and the only context that may save a *_minor column. */
    private function transactional(Closure $callback): mixed
    {
        return Wallet::withAuthorizedBalanceMutation(fn () => DB::transaction($callback));
    }

    private function findByIdempotencyKey(string $key): ?WalletLedgerEntry
    {
        return WalletLedgerEntry::query()->where('idempotency_key', $key)->first();
    }

    private function lockedWallet(string $walletId): Wallet
    {
        return Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    private function assertPositiveAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new WalletException('Amount must be a positive number of minor units.');
        }
    }

    /** Closed wallets can never be used; frozen wallets can still be credited but never debited. */
    private function assertUsable(Wallet $wallet, bool $forDebit): void
    {
        if ($wallet->status === WalletStatus::Closed) {
            throw WalletNotUsableException::forStatus($wallet->id, $wallet->status->value);
        }

        if ($forDebit && $wallet->status !== WalletStatus::Active) {
            throw WalletNotUsableException::forStatus($wallet->id, $wallet->status->value);
        }
    }

    /** @param  array<string, mixed>  $metadata */
    private function postEntry(
        Wallet $wallet,
        WalletLedgerDirection $direction,
        WalletLedgerEntryType $type,
        int $amountMinor,
        int $balanceAfter,
        int $availableAfter,
        int $heldAfter,
        User $actor,
        ?string $idempotencyKey,
        ?string $description,
        ?string $sourceType,
        ?string $sourceId,
        array $metadata,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'currency_id' => $wallet->currency_id,
            'currency_code' => $wallet->currency_code,
            'direction' => $direction,
            'entry_type' => $type,
            'amount_minor' => $amountMinor,
            'balance_after_minor' => $balanceAfter,
            'available_after_minor' => $availableAfter,
            'held_after_minor' => $heldAfter,
            'status' => WalletLedgerStatus::Posted,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'posted_at' => now(),
            'created_by' => $actor->id,
        ]);
    }

    private function logEntry(User $actor, WalletLedgerEntry $entry, string $verb): void
    {
        $this->auditTrail->logUser(
            $actor,
            'wallet_ledger_entries',
            $entry->direction->value,
            sprintf('Wallet %s %s %d minor units (%s).', $entry->wallet_id, $verb, $entry->amount_minor, $entry->entry_type->value),
            $entry,
            [
                'entry_type' => $entry->entry_type->value,
                'amount_minor' => $entry->amount_minor,
                'balance_after_minor' => $entry->balance_after_minor,
            ],
        );
    }
}
