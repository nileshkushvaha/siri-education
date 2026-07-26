<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationExceptions;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorCompensationExceptions\Pages\ListInstructorCompensationExceptions;
use App\Filament\Resources\InstructorCompensationExceptions\Tables\InstructorCompensationExceptionsTable;
use App\Models\InstructorCompensationException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin queue of unresolved compensation exceptions (Phase 14.3):
 * completed lessons whose earning creation was blocked. Rows are
 * system-written, retried by the hourly sweep or manually, resolved —
 * never edited or deleted — once the earning exists.
 */
class InstructorCompensationExceptionResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = InstructorCompensationException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Compensation Exceptions';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return InstructorCompensationExceptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorCompensationExceptions::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor', 'lesson']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = InstructorCompensationException::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorCompensationException::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
