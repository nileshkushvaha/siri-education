<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Models\PackageBenefitRule;
use App\Models\User;
use App\Package\Exceptions\PackageException;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative writer of PackageBenefitRule. Filament
 * calls this service exclusively — never a raw Eloquent create/update
 * from a Resource (mirrors EducationSystemService's role).
 */
final class PackageBenefitRuleService
{
    private const string LOG_NAME = 'package_benefit_rules';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /** @param array{name: string, paid_quantity: int, bonus_quantity?: int, total_quantity: int, is_active?: bool} $data */
    public function create(User $admin, array $data): PackageBenefitRule
    {
        $this->assertCan($admin, 'create', PackageBenefitRule::class);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A name is required.']);
        }

        $paidQuantity = (int) ($data['paid_quantity'] ?? 0);
        $bonusQuantity = (int) ($data['bonus_quantity'] ?? 0);
        $totalQuantity = (int) ($data['total_quantity'] ?? 0);

        $this->assertQuantitiesConsistent($paidQuantity, $bonusQuantity, $totalQuantity);

        return DB::transaction(function () use ($admin, $name, $paidQuantity, $bonusQuantity, $totalQuantity, $data): PackageBenefitRule {
            $rule = PackageBenefitRule::query()->create([
                'name' => $name,
                'paid_quantity' => $paidQuantity,
                'bonus_quantity' => $bonusQuantity,
                'total_quantity' => $totalQuantity,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'package_rule_created', sprintf('Package benefit rule "%s" created.', $rule->name), $rule, [
                'paid_quantity' => $paidQuantity,
                'bonus_quantity' => $bonusQuantity,
                'total_quantity' => $totalQuantity,
            ]);

            return $rule->refresh();
        });
    }

    /** @param array{name?: string, paid_quantity?: int, bonus_quantity?: int, total_quantity?: int, is_active?: bool} $data */
    public function update(User $admin, PackageBenefitRule $rule, array $data): PackageBenefitRule
    {
        $this->assertCan($admin, 'update', $rule);

        $attributes = collect($data)->only(['name', 'paid_quantity', 'bonus_quantity', 'total_quantity', 'is_active'])->all();

        if (array_key_exists('name', $attributes) && trim((string) $attributes['name']) === '') {
            throw ValidationException::withMessages(['name' => 'A name is required.']);
        }

        $paidQuantity = (int) ($attributes['paid_quantity'] ?? $rule->paid_quantity);
        $bonusQuantity = (int) ($attributes['bonus_quantity'] ?? $rule->bonus_quantity);
        $totalQuantity = (int) ($attributes['total_quantity'] ?? $rule->total_quantity);

        $this->assertQuantitiesConsistent($paidQuantity, $bonusQuantity, $totalQuantity);

        return DB::transaction(function () use ($admin, $rule, $attributes): PackageBenefitRule {
            $rule->fill([...$attributes, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'package_rule_updated', sprintf('Package benefit rule "%s" updated.', $rule->name), $rule, [
                'changed_fields' => array_keys($attributes),
            ]);

            return $rule->refresh();
        });
    }

    public function activate(User $admin, PackageBenefitRule $rule): PackageBenefitRule
    {
        return $this->update($admin, $rule, ['is_active' => true]);
    }

    public function deactivate(User $admin, PackageBenefitRule $rule): PackageBenefitRule
    {
        return $this->update($admin, $rule, ['is_active' => false]);
    }

    private function assertQuantitiesConsistent(int $paidQuantity, int $bonusQuantity, int $totalQuantity): void
    {
        if ($totalQuantity !== $paidQuantity + $bonusQuantity) {
            throw new PackageException(sprintf(
                'total_quantity (%d) must equal paid_quantity (%d) + bonus_quantity (%d).',
                $totalQuantity,
                $paidQuantity,
                $bonusQuantity,
            ));
        }
    }

    private function assertCan(User $admin, string $ability, mixed $subject): void
    {
        if (! $admin->can($ability, $subject)) {
            throw new AuthorizationException;
        }
    }
}
