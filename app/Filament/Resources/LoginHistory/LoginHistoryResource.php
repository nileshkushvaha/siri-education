<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginHistory;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\LoginHistory\Pages\ListLoginHistories;
use App\Filament\Resources\LoginHistory\Pages\ViewLoginHistory;
use App\Filament\Resources\LoginHistory\Schemas\LoginHistoryInfolist;
use App\Filament\Resources\LoginHistory\Tables\LoginHistoryTable;
use App\Models\LoginHistory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class LoginHistoryResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = LoginHistory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Login History';

    protected static ?string $modelLabel = 'Login Record';

    protected static ?string $pluralModelLabel = 'Login History';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'login-history';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof LoginHistory) {
            return null;
        }

        $record->loadMissing('user');

        return trim(($record->user?->name ?? 'Unknown user').' · '.($record->logged_in_at?->format('d M Y H:i') ?? ''));
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:LoginHistory');
    }

    public static function canView(Model $record): bool
    {
        return (bool) auth()->user()?->can('View:LoginHistory');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return LoginHistoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoginHistoryTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoginHistories::route('/'),
            'view' => ViewLoginHistory::route('/{record}'),
        ];
    }
}
