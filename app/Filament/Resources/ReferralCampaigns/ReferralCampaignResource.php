<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns;

use App\Filament\Resources\ReferralCampaigns\Pages\CreateReferralCampaign;
use App\Filament\Resources\ReferralCampaigns\Pages\EditReferralCampaign;
use App\Filament\Resources\ReferralCampaigns\Pages\ListReferralCampaigns;
use App\Filament\Resources\ReferralCampaigns\Schemas\ReferralCampaignForm;
use App\Filament\Resources\ReferralCampaigns\Tables\ReferralCampaignsTable;
use App\Models\ReferralCampaign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Campaign administration (SRS 16.22/16.25). Creation and rule edits
 * flow through ReferralCampaignService via the Create/Edit pages;
 * status changes are dedicated service-backed table actions. There is
 * deliberately no delete action anywhere — campaigns archive, never
 * disappear — and no reward or attribution editing on this surface.
 */
class ReferralCampaignResource extends Resource
{
    protected static ?string $model = ReferralCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Referral Campaigns';

    protected static string|\UnitEnum|null $navigationGroup = 'Referral';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ReferralCampaignForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReferralCampaignsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralCampaigns::route('/'),
            'create' => CreateReferralCampaign::route('/create'),
            'edit' => EditReferralCampaign::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ReferralCampaign::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', ReferralCampaign::class) ?? false;
    }
}
