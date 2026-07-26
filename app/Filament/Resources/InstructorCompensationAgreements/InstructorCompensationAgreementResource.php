<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationAgreements;

use App\Earnings\Enums\CompensationAgreementStatus;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorCompensationAgreements\Pages\ListInstructorCompensationAgreements;
use App\Filament\Resources\InstructorCompensationAgreements\Tables\InstructorCompensationAgreementsTable;
use App\Models\InstructorCompensationAgreement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Instructor-specific compensation agreements. Amounts are internal
 * administrator decisions, independent of student pricing —
 * experience/qualifications context is shown to inform the admin, never
 * to compute the amount. Active financial terms are immutable (replace,
 * don't edit); no delete exists; every mutation routes through
 * InstructorCompensationAgreementService.
 */
class InstructorCompensationAgreementResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = InstructorCompensationAgreement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|\UnitEnum|null $navigationGroup = 'Earnings';

    protected static ?string $navigationLabel = 'Compensation Agreements';

    protected static ?int $navigationSort = 0;

    public static function table(Table $table): Table
    {
        return InstructorCompensationAgreementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorCompensationAgreements::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['instructor.profile', 'approver']);
    }

    public static function getNavigationBadge(): ?string
    {
        $drafts = InstructorCompensationAgreement::query()
            ->where('status', CompensationAgreementStatus::Draft)
            ->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorCompensationAgreement::class) ?? false;
    }

    public static function canCreate(): bool
    {
        // Creation happens through the guarded "New agreement" header
        // action, which calls the service — never a generic create page.
        return false;
    }
}
