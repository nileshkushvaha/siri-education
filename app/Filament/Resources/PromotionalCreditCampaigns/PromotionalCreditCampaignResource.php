<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns;

use App\Filament\Resources\PromotionalCreditCampaigns\Pages\CreatePromotionalCreditCampaign;
use App\Filament\Resources\PromotionalCreditCampaigns\Pages\EditPromotionalCreditCampaign;
use App\Filament\Resources\PromotionalCreditCampaigns\Pages\ListPromotionalCreditCampaigns;
use App\Filament\Resources\PromotionalCreditCampaigns\Pages\ViewPromotionalCreditCampaign;
use App\Filament\Resources\PromotionalCreditCampaigns\RelationManagers\IssuancesRelationManager;
use App\Filament\Resources\PromotionalCreditCampaigns\Schemas\PromotionalCreditCampaignForm;
use App\Filament\Resources\PromotionalCreditCampaigns\Schemas\PromotionalCreditCampaignInfolist;
use App\Filament\Resources\PromotionalCreditCampaigns\Tables\PromotionalCreditCampaignsTable;
use App\Models\PromotionalCreditCampaign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Campaign administration (GAP-041, SRS §16.17-§16.19). Creation and
 * rule edits flow through PromotionalCreditService via the Create/Edit
 * pages; status changes are dedicated service-backed table actions.
 * There is deliberately no delete action anywhere — campaigns archive,
 * never disappear.
 */
class PromotionalCreditCampaignResource extends Resource
{
    protected static ?string $model = PromotionalCreditCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Promotional Campaigns';

    protected static string|\UnitEnum|null $navigationGroup = 'Referral';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return PromotionalCreditCampaignForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PromotionalCreditCampaignInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromotionalCreditCampaignsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            IssuancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotionalCreditCampaigns::route('/'),
            'create' => CreatePromotionalCreditCampaign::route('/create'),
            'view' => ViewPromotionalCreditCampaign::route('/{record}'),
            'edit' => EditPromotionalCreditCampaign::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', PromotionalCreditCampaign::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', PromotionalCreditCampaign::class) ?? false;
    }
}
