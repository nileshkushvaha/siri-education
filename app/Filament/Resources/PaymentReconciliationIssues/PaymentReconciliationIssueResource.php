<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentReconciliationIssues;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\PaymentReconciliationIssues\Pages\ListPaymentReconciliationIssues;
use App\Filament\Resources\PaymentReconciliationIssues\Tables\PaymentReconciliationIssuesTable;
use App\Models\PaymentReconciliationIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 4E.2 — the operator queue for discrepancies on the GENERIC
 * payment path (currently package purchases; any future Payable gets
 * it for free).
 *
 * Deliberately grouped under Finance, not Academics. A package
 * discrepancy is a money problem, and the person who needs to see it is
 * whoever reconciles the gateway — not whoever approves package offers.
 * It sits alongside, and does not replace, the Booking domain's own
 * reconciliation queue: two payment architectures coexist by design in
 * this transitional period, so two queues do too.
 *
 * Rows are written exclusively by PaymentReconciliationIssueService.
 * There is no Create page, no Edit page, and no action anywhere on this
 * resource that can alter a payment's amount, provider, status, or the
 * purchase/entitlement behind it. The only mutation offered is
 * "Resolve", which closes this operational row and nothing else.
 */
class PaymentReconciliationIssueResource extends Resource
{
    use HasCentralizedNavigation;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = PaymentReconciliationIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Package Payment Issues';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return PaymentReconciliationIssuesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentReconciliationIssues::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['payment', 'resolver']);
    }

    /** Open discrepancies are money nobody has accounted for — surface the count. */
    public static function getNavigationBadge(): ?string
    {
        $open = PaymentReconciliationIssue::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', PaymentReconciliationIssue::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
