<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackagePurchases;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\StudentPackagePurchases\Pages\ListStudentPackagePurchases;
use App\Filament\Resources\StudentPackagePurchases\Tables\StudentPackagePurchasesTable;
use App\Models\StudentPackagePurchase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only admin visibility into accepted package purchases and their
 * payment progress.
 *
 * This exists because Phase 4B.2 moved entitlement creation behind
 * payment: without it, an accepted-but-unpaid package would be
 * invisible to admins, since no entitlement row exists yet.
 *
 * Deliberately list-only with no create/edit/delete for anyone,
 * including admin — a purchase is written by acceptance and settled by
 * a verified webhook. Payment records are never hand-editable through
 * Filament (see StudentPackagePurchasePolicy).
 */
class StudentPackagePurchaseResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = StudentPackagePurchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Student Package Purchases';

    protected static ?string $modelLabel = 'Student Package Purchase';

    protected static ?string $pluralModelLabel = 'Student Package Purchases';

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return StudentPackagePurchasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentPackagePurchases::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['student', 'proposal.instructor', 'proposal.packageBenefitRule']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', StudentPackagePurchase::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
