<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPackageProposals;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorPackageProposals\Pages\ListInstructorPackageProposals;
use App\Filament\Resources\InstructorPackageProposals\Tables\InstructorPackageProposalsTable;
use App\Models\InstructorPackageProposal;
use App\Package\Enums\InstructorPackageProposalStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proposals are created only by instructors through the frontend — no
 * Create or Edit pages exist here, mirroring
 * InstructorWithdrawalRequestResource exactly. Every lifecycle action
 * (approve/reject, with optional audited price override) delegates to
 * InstructorPackageProposalService; the calculation breakdown is shown
 * inline in the table rather than a separate view page.
 */
class InstructorPackageProposalResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = InstructorPackageProposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGiftTop;

    protected static ?string $navigationLabel = 'Instructor Package Proposals';

    protected static ?string $modelLabel = 'Instructor Package Proposal';

    protected static ?string $pluralModelLabel = 'Instructor Package Proposals';

    public static function table(Table $table): Table
    {
        return InstructorPackageProposalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorPackageProposals::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['instructor', 'student', 'packageBenefitRule', 'academicContext']);
    }

    public static function getNavigationBadge(): ?string
    {
        $submitted = InstructorPackageProposal::query()
            ->where('status', InstructorPackageProposalStatus::Submitted)
            ->count();

        return $submitted > 0 ? (string) $submitted : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', InstructorPackageProposal::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
