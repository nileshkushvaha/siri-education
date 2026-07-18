<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralRewards;

use App\Filament\Resources\ReferralRewards\Pages\ListReferralRewards;
use App\Filament\Resources\ReferralRewards\Tables\ReferralRewardsTable;
use App\Models\ReferralReward;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The referral reward operations queue (SRS 16.25): held reviews,
 * credit-failure retries, and required reversals — every decision is a
 * service-backed action with a mandatory reason; there is no form, no
 * edit, no delete, and no direct model mutation. Wallet balances,
 * payment references and lesson internals never appear here.
 */
class ReferralRewardResource extends Resource
{
    protected static ?string $model = ReferralReward::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Referral Rewards';

    protected static string|\UnitEnum|null $navigationGroup = 'Referral';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return ReferralRewardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralRewards::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ReferralReward::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
