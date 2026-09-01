<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Users\Tables\RoleScopedUsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The student-only view of the user list. Same model, same policy and
 * same table as UserResource — only the query is scoped — so admins can
 * open "Students" instead of filtering "All Users" by role every time.
 *
 * Read/route surface only: it owns no form and no edit page. Row actions
 * link to UserResource's pages, which remain the single place a user is
 * created, edited and audit-logged.
 */
class StudentResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'students';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static bool $isGloballySearchable = false;

    // The model is User, so without these every heading, breadcrumb and
    // empty state on this resource would say "Users".
    protected static ?string $modelLabel = 'student';

    protected static ?string $pluralModelLabel = 'students';

    public static function table(Table $table): Table
    {
        return RoleScopedUsersTable::configure(
            $table,
            'student',
            'No students yet',
            'Users with the student role will appear here.',
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * whereHasRoleNamed() (not Spatie's role() scope) so a
     * temporarily-missing 'student' role renders an empty list rather
     * than throwing RoleDoesNotExist — same reasoning as
     * InstructorOnboardingResource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHasRoleNamed('student');
    }
}
