<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorSettlementBatches;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorSettlementBatches\Pages\ListInstructorSettlementBatches;
use App\Filament\Resources\InstructorSettlementBatches\Tables\InstructorSettlementBatchesTable;
use App\Models\InstructorSettlementBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batches are created through the "New Settlement Batch" header action
 * (which delegates to InstructorEarningService) — no standard Create
 * form, no Edit page. Marking paid is a manual admin action; no
 * external transfer is executed anywhere in the panel.
 */
class InstructorSettlementBatchResource extends Resource
{
    use HasCentralizedNavigation;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = InstructorSettlementBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Settlement Batches';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return InstructorSettlementBatchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorSettlementBatches::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor'])->withCount('earnings');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorSettlementBatch::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
