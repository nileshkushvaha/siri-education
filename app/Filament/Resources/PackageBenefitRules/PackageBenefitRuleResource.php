<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\PackageBenefitRules\Pages\CreatePackageBenefitRule;
use App\Filament\Resources\PackageBenefitRules\Pages\EditPackageBenefitRule;
use App\Filament\Resources\PackageBenefitRules\Pages\ListPackageBenefitRules;
use App\Filament\Resources\PackageBenefitRules\Schemas\PackageBenefitRuleForm;
use App\Filament\Resources\PackageBenefitRules\Tables\PackageBenefitRulesTable;
use App\Models\PackageBenefitRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Admin-managed reusable package offer templates (e.g. "14 paid
 * lessons + 1 bonus lesson") — see docs/architecture/domain-registry.md
 * "Personalized Packages". Carries no price; price is always resolved
 * per-proposal from StudentLessonPrice.
 *
 * User-facing terminology is "Package Offer"; the model/table keep
 * their `PackageBenefitRule`/`package_benefit_rules` names (internal
 * only, never shown to an admin or instructor).
 */
class PackageBenefitRuleResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = PackageBenefitRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Package Offers';

    protected static ?string $modelLabel = 'Package Offer';

    protected static ?string $pluralModelLabel = 'Package Offers';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PackageBenefitRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PackageBenefitRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackageBenefitRules::route('/'),
            'create' => CreatePackageBenefitRule::route('/create'),
            'edit' => EditPackageBenefitRule::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', PackageBenefitRule::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', PackageBenefitRule::class) ?? false;
    }
}
