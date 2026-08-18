<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiQualityInsights;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\AiQualityInsights\Pages\ListAiQualityInsights;
use App\Filament\Resources\AiQualityInsights\Pages\ViewAiQualityInsight;
use App\Filament\Resources\AiQualityInsights\Schemas\AiQualityInsightInfolist;
use App\Filament\Resources\AiQualityInsights\Tables\AiQualityInsightsTable;
use App\Models\AiQualityInsight;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin-only AI quality briefings. Read-and-generate: there is no
 * create form and no edit page for anyone including admin, because an
 * insight is produced by a run and only ever read by a person — the
 * one write an administrator makes is "Mark reviewed".
 *
 * Not shown to instructors or students at any point (see
 * AiQualityInsightPolicy).
 */
class AiQualityInsightResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = AiQualityInsight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Quality Insights';

    protected static ?string $modelLabel = 'AI Quality Insight';

    protected static ?string $pluralModelLabel = 'AI Quality Insights';

    protected static string|\UnitEnum|null $navigationGroup = 'Quality & Compliance';

    protected static ?string $slug = 'ai-quality-insights';

    public static function table(Table $table): Table
    {
        return AiQualityInsightsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AiQualityInsightInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiQualityInsights::route('/'),
            'view' => ViewAiQualityInsight::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor', 'requestedBy', 'reviewedBy']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', AiQualityInsight::class) ?? false;
    }

    /** Insights are generated, never hand-written. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
