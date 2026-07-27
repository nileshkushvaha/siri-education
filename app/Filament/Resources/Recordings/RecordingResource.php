<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Recordings\Pages\ListRecordings;
use App\Filament\Resources\Recordings\Pages\ViewRecording;
use App\Filament\Resources\Recordings\Schemas\RecordingInfolist;
use App\Filament\Resources\Recordings\Tables\RecordingsTable;
use App\Models\Recording;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only admin visibility. No create/edit/
 * delete anywhere: RecordingService is the only writer, and a
 * recording is never administratively deleted (only expired, keeping
 * its metadata).
 */
class RecordingResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = Recording::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static ?string $navigationLabel = 'Recordings';

    protected static ?string $modelLabel = 'Recording';

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return RecordingsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecordingInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecordings::route('/'),
            'view' => ViewRecording::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Recording::class) ?? false;
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
