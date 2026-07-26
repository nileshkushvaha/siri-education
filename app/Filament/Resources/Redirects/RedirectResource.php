<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Filament\Resources\Redirects\Pages\EditRedirect;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Filament\Resources\Redirects\Pages\ViewRedirect;
use App\Filament\Resources\Redirects\Schemas\RedirectForm;
use App\Filament\Resources\Redirects\Schemas\RedirectInfolist;
use App\Filament\Resources\Redirects\Tables\RedirectsTable;
use App\Models\Redirect;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * GAP-036 (SRS §22.25/26) — managed 301/302 redirect administration.
 * Every mutation flows through RedirectService via the Create/Edit
 * pages and the table's activate/deactivate actions; this resource is
 * wiring only.
 */
class RedirectResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Redirects';

    protected static ?string $modelLabel = 'Redirect';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'source_path';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return RedirectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RedirectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RedirectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedirects::route('/'),
            'create' => CreateRedirect::route('/create'),
            'view' => ViewRedirect::route('/{record}'),
            'edit' => EditRedirect::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Redirect::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Redirect::class) ?? false;
    }

    /** No delete action anywhere — redirects are deactivated, never hard deleted (requirement #5). */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
