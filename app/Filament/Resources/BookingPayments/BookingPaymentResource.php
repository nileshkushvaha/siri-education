<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\BookingPayments\Pages\ListBookingPayments;
use App\Filament\Resources\BookingPayments\Pages\ViewBookingPayment;
use App\Filament\Resources\BookingPayments\Schemas\BookingPaymentInfolist;
use App\Filament\Resources\BookingPayments\Tables\BookingPaymentsTable;
use App\Models\BookingPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only by design: no Create/Edit page. A row is only ever written
 * by RazorpayPaymentProvider (order creation) and the checkout-verify /
 * webhook flow that follows it — never a raw admin form field, and
 * never a bulk action (a "mark paid" bulk action here would bypass
 * BookingPaymentService::markPaid()'s reference/idempotency guard).
 */
class BookingPaymentResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = BookingPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $modelLabel = 'Payment';

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return BookingPaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingPaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookingPayments::route('/'),
            'view' => ViewBookingPayment::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['booking', 'user']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', BookingPayment::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
