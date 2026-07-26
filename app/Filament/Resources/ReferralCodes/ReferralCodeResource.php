<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCodes;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\ReferralCodes\Pages\ListReferralCodes;
use App\Filament\Resources\ReferralCodes\Tables\ReferralCodesTable;
use App\Models\ReferralCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Referral-code inspection and the single abuse response: disable with
 * a mandatory reason via ReferralCodeService::disable(). No delete, no
 * edit, no regenerate, no re-enable, no manual code choice — a
 * disabled code stays disabled and existing attribution/reward history
 * is untouched.
 */
class ReferralCodeResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = ReferralCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Referral Codes';

    protected static string|\UnitEnum|null $navigationGroup = 'Referral';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return ReferralCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralCodes::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ReferralCode::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
