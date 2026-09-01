<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding;

use App\Enums\InstructorStatus;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\Pages\ListInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\RelationManagers\CurriculumEligibilityRelationManager;
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
    use HasCentralizedNavigation;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'instructor-onboarding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Instructor';

    protected static ?string $navigationLabel = 'Onboarding';

    protected static ?int $navigationSort = -1;

    protected static ?string $recordTitleAttribute = 'name';

    // The model is User, so without these the page heading and breadcrumb
    // on a screen that only ever shows instructor applications read
    // "Users" — the exact confusion the People regroup set out to remove.
    protected static ?string $modelLabel = 'instructor application';

    protected static ?string $pluralModelLabel = 'instructor onboarding';

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
            CurriculumEligibilityRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Joined (not just eager-loaded) so instructor_status/timestamps —
        // which live on user_profiles — are sortable/filterable as
        // ordinary table columns instead of needing relation-query
        // gymnastics on every column and filter.
        //
        // whereHasRoleNamed() (not Spatie's role() scope) so a
        // temporarily-missing 'instructor' role renders an empty list
        // instead of a 500 — this resource's own read path, reached only
        // when canViewAny() already passed.
        return parent::getEloquentQuery()
            ->whereHasRoleNamed('instructor')
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

    /**
     * Filament evaluates every registered resource's
     * getNavigationBadge() on every admin page load, not just this
     * resource's own pages. The Spatie `role()` query scope pre-resolves
     * the role name via Role::findByName(), which throws RoleDoesNotExist
     * if that row doesn't exist yet (fresh install, partial deployment,
     * seeder not yet run) — crashing completely unrelated admin pages
     * with a 500. whereHasRoleNamed() performs the identical
     * join when the role exists, and simply matches zero rows (not an
     * exception) when it doesn't — a missing optional role record must
     * never crash navigation.
     */
    public static function pendingReviewQuery(): Builder
    {
        return User::query()
            ->whereHasRoleNamed('instructor')
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
