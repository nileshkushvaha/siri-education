<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralAttributions;

use App\Filament\Resources\ReferralAttributions\Pages\ListReferralAttributions;
use App\Filament\Resources\ReferralAttributions\Tables\ReferralAttributionsTable;
use App\Models\ReferralAttribution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only attribution inspection (SRS 16.25) plus the single
 * exceptional correction action — service-backed, permission-gated,
 * refused once any reward exists. No edit form, no delete, ever.
 */
class ReferralAttributionResource extends Resource
{
    protected static ?string $model = ReferralAttribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Referral Attributions';

    protected static string|\UnitEnum|null $navigationGroup = 'Referral';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return ReferralAttributionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralAttributions::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ReferralAttribution::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
