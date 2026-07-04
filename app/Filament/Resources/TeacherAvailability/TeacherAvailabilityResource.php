<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability;

use App\Filament\Resources\TeacherAvailability\Pages\CreateTeacherAvailability;
use App\Filament\Resources\TeacherAvailability\Pages\EditTeacherAvailability;
use App\Filament\Resources\TeacherAvailability\Pages\ListTeacherAvailability;
use App\Filament\Resources\TeacherAvailability\Schemas\TeacherAvailabilityForm;
use App\Filament\Resources\TeacherAvailability\Tables\TeacherAvailabilityTable;
use App\Models\TeacherAvailability;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeacherAvailabilityResource extends Resource
{
    protected static ?string $model = TeacherAvailability::class;

    protected static ?string $slug = 'teacher-availability';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Teacher Availability';

    protected static ?string $modelLabel = 'Availability Window';

    protected static string|\UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return TeacherAvailabilityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeacherAvailabilityTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeacherAvailability::route('/'),
            'create' => CreateTeacherAvailability::route('/create'),
            'edit' => EditTeacherAvailability::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('teacher');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', TeacherAvailability::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', TeacherAvailability::class) ?? false;
    }
}
