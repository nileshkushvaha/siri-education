<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPaymentReconciliationIssues;

use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\BookingPaymentReconciliationIssues\Pages\ListBookingPaymentReconciliationIssues;
use App\Filament\Resources\BookingPaymentReconciliationIssues\Tables\BookingPaymentReconciliationIssuesTable;
use App\Models\BookingPaymentReconciliationIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finance-visible reconciliation queue for the collection domain —
 * mirrors InstructorPayoutReconciliationIssueResource. Rows are written
 * exclusively by BookingPaymentReconciliationService —
 * no Create or Edit page exists. Resolution closes the issue only; it
 * never marks a booking paid or otherwise mutates payment state.
 */
class BookingPaymentReconciliationIssueResource extends Resource
{
    use HasCentralizedNavigation;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = BookingPaymentReconciliationIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?string $navigationLabel = 'Booking Payment Issues';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return BookingPaymentReconciliationIssuesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookingPaymentReconciliationIssues::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['bookingPayment', 'assignee']);
    }

    /**
     * Authorization is checked BEFORE the count is built, not after it is
     * rendered. Navigation hiding alone is not a boundary: an operator
     * with no access to this queue must not cause a single row of it to
     * be read, or "there are 7 open booking payment issues" leaks through
     * a sidebar they cannot open.
     *
     * Counts only what an operator can actually act on: open incidents
     * whose type the platform still generates. A badge that included
     * dormant historical types would show a number the queue's own
     * filters cannot reproduce. Such rows remain visible in the
     * unfiltered table — they are simply not counted as today's work.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $open = BookingPaymentReconciliationIssue::query()
            ->open()
            ->whereIn('type', BookingPaymentReconciliationIssueType::live())
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', BookingPaymentReconciliationIssue::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
