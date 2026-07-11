<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorEarnings;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Filament\Resources\InstructorEarnings\Pages\ListInstructorEarnings;
use App\Filament\Resources\InstructorEarnings\Tables\InstructorEarningsTable;
use App\Models\InstructorEarning;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Earnings are created by the lifecycle engine (LessonCompleted), never
 * from the panel — no Create or Edit pages. Lifecycle actions delegate
 * to InstructorEarningServiceInterface; amounts are never edited
 * casually and settled rows are immutable.
 */
class InstructorEarningResource extends Resource
{
    protected static ?string $model = InstructorEarning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Instructor Earnings';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return InstructorEarningsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorEarnings::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor', 'subject', 'settlementBatch', 'lesson']);
    }

    public static function getNavigationBadge(): ?string
    {
        $releasable = InstructorEarning::query()
            ->where('status', InstructorEarningStatus::Releasable)
            ->count();

        return $releasable > 0 ? (string) $releasable : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorEarning::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
