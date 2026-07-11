<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutMethods;

use App\Earnings\Enums\PayoutMethodStatus;
use App\Filament\Resources\InstructorPayoutMethods\Pages\ListInstructorPayoutMethods;
use App\Filament\Resources\InstructorPayoutMethods\Tables\InstructorPayoutMethodsTable;
use App\Models\InstructorPayoutMethod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payout methods are created only by instructors through the frontend
 * — no Create or Edit pages exist here, and verified details are
 * immutable everywhere. The table shows masked identifiers only;
 * decrypting details is a dedicated, permission-gated, audit-logged
 * action. Lifecycle mutations delegate to
 * InstructorPayoutMethodServiceInterface.
 */
class InstructorPayoutMethodResource extends Resource
{
    protected static ?string $model = InstructorPayoutMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Payout Methods';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return InstructorPayoutMethodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorPayoutMethods::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor', 'country', 'verifier']);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = InstructorPayoutMethod::query()
            ->where('status', PayoutMethodStatus::PendingVerification)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorPayoutMethod::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
