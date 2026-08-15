<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutReconciliationIssues;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\Pages\ListInstructorPayoutReconciliationIssues;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\Tables\InstructorPayoutReconciliationIssuesTable;
use App\Models\InstructorPayoutReconciliationIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finance-visible reconciliation queue. Rows are written exclusively
 * by InstructorPayoutReconciliationService — no Create or
 * Edit page exists. Resolution closes the issue only; it never marks a
 * withdrawal paid or otherwise mutates payout state.
 */
class InstructorPayoutReconciliationIssueResource extends Resource
{
    use HasCentralizedNavigation;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = InstructorPayoutReconciliationIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Payout Reconciliation';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return InstructorPayoutReconciliationIssuesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorPayoutReconciliationIssues::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['withdrawalRequest', 'payoutAttempt', 'assignee']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = InstructorPayoutReconciliationIssue::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorPayoutReconciliationIssue::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
