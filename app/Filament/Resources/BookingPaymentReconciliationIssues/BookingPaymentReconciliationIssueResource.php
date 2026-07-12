<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPaymentReconciliationIssues;

use App\Filament\Resources\BookingPaymentReconciliationIssues\Pages\ListBookingPaymentReconciliationIssues;
use App\Filament\Resources\BookingPaymentReconciliationIssues\Tables\BookingPaymentReconciliationIssuesTable;
use App\Models\BookingPaymentReconciliationIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finance-visible reconciliation queue for the collection domain
 * (Phase 16C) — mirrors InstructorPayoutReconciliationIssueResource.
 * Rows are written exclusively by BookingPaymentReconciliationService —
 * no Create or Edit page exists. Resolution closes the issue only; it
 * never marks a booking paid or otherwise mutates payment state.
 */
class BookingPaymentReconciliationIssueResource extends Resource
{
    protected static ?string $model = BookingPaymentReconciliationIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?string $navigationLabel = 'Payment Reconciliation';

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

    public static function getNavigationBadge(): ?string
    {
        $open = BookingPaymentReconciliationIssue::query()->open()->count();

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
