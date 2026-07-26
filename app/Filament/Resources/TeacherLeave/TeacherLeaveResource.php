<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\TeacherLeave\Pages\CreateTeacherLeave;
use App\Filament\Resources\TeacherLeave\Pages\EditTeacherLeave;
use App\Filament\Resources\TeacherLeave\Pages\ListTeacherLeave;
use App\Filament\Resources\TeacherLeave\Schemas\TeacherLeaveForm;
use App\Filament\Resources\TeacherLeave\Tables\TeacherLeaveTable;
use App\Models\TeacherUnavailability;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeacherLeaveResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = TeacherUnavailability::class;

    protected static ?string $slug = 'teacher-leave';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static ?string $navigationLabel = 'Teacher Leave';

    protected static ?string $modelLabel = 'Leave';

    protected static ?string $pluralModelLabel = 'Leave';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return TeacherLeaveForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeacherLeaveTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeacherLeave::route('/'),
            'create' => CreateTeacherLeave::route('/create'),
            'edit' => EditTeacherLeave::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('teacher');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', TeacherUnavailability::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', TeacherUnavailability::class) ?? false;
    }
}
