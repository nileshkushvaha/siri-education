<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReviewTags;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\ReviewTags\Pages\CreateReviewTag;
use App\Filament\Resources\ReviewTags\Pages\EditReviewTag;
use App\Filament\Resources\ReviewTags\Pages\ListReviewTags;
use App\Filament\Resources\ReviewTags\Schemas\ReviewTagForm;
use App\Filament\Resources\ReviewTags\Tables\ReviewTagsTable;
use App\Models\ReviewTag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin-curated review tags (Phase 17U.2 §7). Never hard-deleted — no
 * `deleted_at` column exists at all; a tag is retired via `is_active`
 * only, so historical review snapshots (each review stores its own
 * key/label snapshot at submission time, see ReviewTag::snapshot())
 * never change and a deactivated tag simply stops being offered to new
 * submissions (SubmitLessonReviewAction::resolveTags() already filters
 * on ->active()).
 */
class ReviewTagResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = ReviewTag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Review Tags';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return ReviewTagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReviewTagsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewTags::route('/'),
            'create' => CreateReviewTag::route('/create'),
            'edit' => EditReviewTag::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ReviewTag::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', ReviewTag::class) ?? false;
    }
}
