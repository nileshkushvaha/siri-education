<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\BookingTypes\Pages\CreateBookingType;
use App\Filament\Resources\BookingTypes\Pages\EditBookingType;
use App\Filament\Resources\BookingTypes\Pages\ListBookingTypes;
use App\Filament\Resources\BookingTypes\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\BookingTypes\Schemas\BookingTypeForm;
use App\Filament\Resources\BookingTypes\Tables\BookingTypesTable;
use App\Models\BookingType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BookingTypeResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = BookingType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Booking Types';

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return BookingTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookingTypes::route('/'),
            'create' => CreateBookingType::route('/create'),
            'edit' => EditBookingType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', BookingType::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', BookingType::class) ?? false;
    }
}
