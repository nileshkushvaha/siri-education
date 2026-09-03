<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Users\Pages\ListInstructors;
use App\Filament\Resources\Users\Tables\RoleScopedUsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The instructor-only view of the user list. Same model, same policy and
 * same table as UserResource — only the query is scoped — so admins can
 * open "Instructors" instead of filtering "All Users" by role every time.
 *
 * Distinct from InstructorOnboardingResource, which is the *application
 * review* surface (scoped to review-relevant fields and lifecycle
 * actions, gated on `instructor.applications.review`). This one is the
 * plain account roster, gated on the ordinary User policy.
 *
 * Read/route surface only: it owns no form and no edit page. Row actions
 * link to UserResource's pages, which remain the single place a user is
 * created, edited and audit-logged.
 */
class InstructorResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'instructors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static bool $isGloballySearchable = false;

    // The model is User, so without these every heading, breadcrumb and
    // empty state on this resource would say "Users".
    protected static ?string $modelLabel = 'instructor';

    protected static ?string $pluralModelLabel = 'instructors';

    public static function table(Table $table): Table
    {
        return RoleScopedUsersTable::configure(
            $table,
            'instructor',
            'No instructors yet',
            'Users with the instructor role will appear here.',
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructors::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * whereHasRoleNamed() (not Spatie's role() scope) so a
     * temporarily-missing 'instructor' role renders an empty list rather
     * than throwing RoleDoesNotExist — same reasoning as
     * InstructorOnboardingResource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHasRoleNamed('instructor');
    }
}
