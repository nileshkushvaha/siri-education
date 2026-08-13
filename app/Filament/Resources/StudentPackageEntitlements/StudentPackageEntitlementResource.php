<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackageEntitlements;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\StudentPackageEntitlements\Pages\ListStudentPackageEntitlements;
use App\Filament\Resources\StudentPackageEntitlements\Tables\StudentPackageEntitlementsTable;
use App\Models\StudentPackageEntitlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only admin visibility into student lesson balances. Entitlements
 * are created solely by accepting a proposal and drawn down solely by
 * PackageEntitlementService — there is deliberately no create, edit, or
 * delete surface here for anyone, including admin (see
 * StudentPackageEntitlementPolicy).
 */
class StudentPackageEntitlementResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = StudentPackageEntitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Student Package Entitlements';

    protected static ?string $modelLabel = 'Student Package Entitlement';

    protected static ?string $pluralModelLabel = 'Student Package Entitlements';

    public static function table(Table $table): Table
    {
        return StudentPackageEntitlementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentPackageEntitlements::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['student', 'instructor', 'proposal.packageBenefitRule']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', StudentPackageEntitlement::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
