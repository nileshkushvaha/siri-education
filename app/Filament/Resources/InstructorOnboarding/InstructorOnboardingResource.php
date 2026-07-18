<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\Pages\ListInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\Tables\InstructorOnboardingTable;
use App\Filament\Resources\Users\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\Users\RelationManagers\EducationsRelationManager;
use App\Filament\Resources\Users\RelationManagers\ExperiencesRelationManager;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A dedicated, review-focused view of instructor applicants/instructors
 * — separate from UserResource so admins aren't hunting for pending
 * applications inside the general user list. Its own edit page shows
 * only instructor fields and the lifecycle actions (both reused from
 * UserResource via UserForm::instructorProfileSchema() and
 * HasInstructorLifecycleActions — never duplicated).
 */
class InstructorOnboardingResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'instructor-onboarding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Instructor';

    protected static ?string $navigationLabel = 'Onboarding';

    protected static ?int $navigationSort = -1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return InstructorOnboardingTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructorOnboarding::route('/'),
            'edit' => EditInstructorOnboarding::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            ExperiencesRelationManager::class,
            EducationsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Joined (not just eager-loaded) so instructor_status/timestamps —
        // which live on user_profiles — are sortable/filterable as
        // ordinary table columns instead of needing relation-query
        // gymnastics on every column and filter.
        return parent::getEloquentQuery()
            ->role('instructor')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->select('users.*')
            ->addSelect([
                'user_profiles.instructor_status',
                'user_profiles.instructor_application_started_at',
                'user_profiles.instructor_application_submitted_at',
                'user_profiles.instructor_reviewed_at',
            ])
            ->with('profile');
    }

    public static function pendingReviewQuery(): Builder
    {
        return User::query()
            ->role('instructor')
            ->whereHas('profile', fn (Builder $query) => $query->whereIn('instructor_status', InstructorStatus::needsReview()));
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::pendingReviewQuery()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return static::canReview();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canReview();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function canReview(): bool
    {
        $admin = auth()->user();

        return $admin instanceof User && app(InstructorOnboardingService::class)->canReviewApplications($admin);
    }
}
