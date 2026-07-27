<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\StudentLessonPrices\Pages\CreateStudentLessonPrice;
use App\Filament\Resources\StudentLessonPrices\Pages\EditStudentLessonPrice;
use App\Filament\Resources\StudentLessonPrices\Pages\ListStudentLessonPrices;
use App\Filament\Resources\StudentLessonPrices\Schemas\StudentLessonPriceForm;
use App\Filament\Resources\StudentLessonPrices\Tables\StudentLessonPricesTable;
use App\Models\StudentLessonPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Admin-only student-facing pricing matrix. Never
 * surfaced to the instructor role/portal — see
 * docs/architecture/phase-10.2d-student-pricing-matrix.md.
 */
class StudentLessonPriceResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = StudentLessonPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Student Lesson Prices';

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return StudentLessonPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentLessonPricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentLessonPrices::route('/'),
            'create' => CreateStudentLessonPrice::route('/create'),
            'edit' => EditStudentLessonPrice::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', StudentLessonPrice::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', StudentLessonPrice::class) ?? false;
    }
}
