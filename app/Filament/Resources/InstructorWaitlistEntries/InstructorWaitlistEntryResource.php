<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWaitlistEntries;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorWaitlistEntries\Pages\ListInstructorWaitlistEntries;
use App\Filament\Resources\InstructorWaitlistEntries\Pages\ViewInstructorWaitlistEntry;
use App\Filament\Resources\InstructorWaitlistEntries\Schemas\InstructorWaitlistEntryInfolist;
use App\Filament\Resources\InstructorWaitlistEntries\Tables\InstructorWaitlistEntriesTable;
use App\Models\InstructorWaitlistEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only by design: no Create/Edit page. A row is only ever
 * written by WaitlistService — never a raw admin form field, never a
 * bulk action.
 */
class InstructorWaitlistEntryResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = InstructorWaitlistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Waitlist';

    protected static ?string $modelLabel = 'Waitlist Entry';

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return InstructorWaitlistEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstructorWaitlistEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorWaitlistEntries::route('/'),
            'view' => ViewInstructorWaitlistEntry::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'instructor']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorWaitlistEntry::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
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
}
