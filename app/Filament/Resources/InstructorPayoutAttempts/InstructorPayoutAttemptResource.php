<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutAttempts;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Filament\Resources\InstructorPayoutAttempts\Pages\ListInstructorPayoutAttempts;
use App\Filament\Resources\InstructorPayoutAttempts\Tables\InstructorPayoutAttemptsTable;
use App\Models\InstructorPayoutAttempt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payout attempts are created only by InstructorPayoutExecutionService —
 * no Create or Edit page exists here. Every action (cancel, reconcile
 * now, manual retry) delegates to the execution/reconciliation
 * services; no action ever mutates status/amount/destination directly.
 */
class InstructorPayoutAttemptResource extends Resource
{
    protected static ?string $model = InstructorPayoutAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Payout Attempts';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return InstructorPayoutAttemptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorPayoutAttempts::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['withdrawalRequest', 'instructor', 'initiator']);
    }

    public static function getNavigationBadge(): ?string
    {
        $needsAttention = InstructorPayoutAttempt::query()
            ->whereIn('status', [InstructorPayoutAttemptStatus::Unknown, InstructorPayoutAttemptStatus::Failed])
            ->count();

        return $needsAttention > 0 ? (string) $needsAttention : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorPayoutAttempt::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
