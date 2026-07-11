<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWithdrawalRequests;

use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Filament\Resources\InstructorWithdrawalRequests\Pages\ListInstructorWithdrawalRequests;
use App\Filament\Resources\InstructorWithdrawalRequests\Tables\InstructorWithdrawalRequestsTable;
use App\Models\InstructorWithdrawalRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Withdrawal requests are created only by instructors through the
 * frontend — no Create or Edit pages exist, amounts/destination/status
 * are immutable, and every lifecycle action delegates to
 * InstructorWithdrawalServiceInterface. Phase 15 ends at approval: no
 * mark-paid action exists anywhere.
 */
class InstructorWithdrawalRequestResource extends Resource
{
    protected static ?string $model = InstructorWithdrawalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Withdrawal Requests';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return InstructorWithdrawalRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorWithdrawalRequests::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor', 'reviewer', 'approver']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = InstructorWithdrawalRequest::query()
            ->whereIn('status', [InstructorWithdrawalStatus::Submitted, InstructorWithdrawalStatus::UnderReview])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorWithdrawalRequest::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
