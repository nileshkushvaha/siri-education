<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Models\Currency;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saves the per-currency wallet rules edited on Settings → Wallet:
 * minimum / maximum recharge, the recharge step (amount must be a whole
 * multiple of it) and the low-balance alert threshold. Amounts arrive as
 * major-unit strings ("500", "10.50") and are stored as integer minor
 * units in that currency's own exponent. Blank means "not configured".
 */
final class WalletCurrencyLimitService
{
    public const FIELDS = [
        'minimum_recharge_minor' => 'Minimum recharge',
        'maximum_recharge_minor' => 'Maximum recharge',
        'recharge_multiple_minor' => 'Recharge step',
        'low_balance_threshold_minor' => 'Low-balance alert',
    ];

    public function __construct(private readonly AuditTrailService $auditTrail) {}

    /**
     * Current values as major-unit strings, keyed by currency id.
     *
     * @return array<int, array{code: string, name: string, minor_units: int, minimum_recharge_minor: ?string, maximum_recharge_minor: ?string, recharge_multiple_minor: ?string, low_balance_threshold_minor: ?string}>
     */
    public function current(): array
    {
        return Currency::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(function (Currency $currency): array {
                $row = ['code' => $currency->code, 'name' => $currency->name, 'minor_units' => (int) $currency->minor_units];

                foreach (array_keys(self::FIELDS) as $field) {
                    $row[$field] = $currency->{$field} === null ? null : MoneyFormatter::toMajor((int) $currency->{$field}, (int) $currency->minor_units);
                }

                return [$currency->id => $row];
            })
            ->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  currency id => [field => major-unit string|null]
     * @return int number of currencies that changed
     *
     * @throws InvalidArgumentException when an amount does not fit the currency's decimal places
     */
    public function update(User $admin, array $rows): int
    {
        $changed = 0;

        DB::transaction(function () use ($admin, $rows, &$changed): void {
            foreach ($rows as $currencyId => $values) {
                $currency = Currency::query()->lockForUpdate()->find($currencyId);

                if ($currency === null) {
                    continue;
                }

                $before = [];
                $after = [];

                foreach (array_keys(self::FIELDS) as $field) {
                    if (! array_key_exists($field, $values)) {
                        continue;
                    }

                    $minor = blank($values[$field])
                        ? null
                        : MoneyFormatter::toMinor(trim((string) $values[$field]), (int) $currency->minor_units);

                    if ($minor !== null && $minor < 0) {
                        throw new InvalidArgumentException(sprintf('%s for %s cannot be negative.', self::FIELDS[$field], $currency->code));
                    }

                    if ($field === 'recharge_multiple_minor' && $minor !== null && $minor === 0) {
                        throw new InvalidArgumentException(sprintf('Recharge step for %s must be greater than zero, or left blank for no step.', $currency->code));
                    }

                    if ($currency->{$field} !== $minor) {
                        $before[$field] = $currency->{$field};
                        $after[$field] = $minor;
                        $currency->{$field} = $minor;
                    }
                }

                if (
                    $currency->minimum_recharge_minor !== null
                    && $currency->maximum_recharge_minor !== null
                    && $currency->maximum_recharge_minor < $currency->minimum_recharge_minor
                ) {
                    throw new InvalidArgumentException(sprintf('Maximum recharge for %s must not be lower than the minimum.', $currency->code));
                }

                if ($after === []) {
                    continue;
                }

                $currency->save();
                $changed++;

                $this->auditTrail->logUser($admin, 'settings', 'wallet_limits_updated', "Wallet limits updated for {$currency->code}", $currency, [
                    'currency' => $currency->code,
                    'before' => $before,
                    'after' => $after,
                ]);
            }
        });

        return $changed;
    }
}
