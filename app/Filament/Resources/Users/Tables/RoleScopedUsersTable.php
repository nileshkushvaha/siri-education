<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ExportBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The shared table for the role-scoped user lists (Students,
 * Instructors) that sit alongside "All Users" in the People section.
 *
 * Filters and empty/sort behaviour are UsersTable's — never a second
 * copy of them. Two things deliberately differ:
 *
 *  - Columns. "Roles" is noise on a list that is already one role, so it
 *    goes, and the one lifecycle status that matters for this role is
 *    shown outright instead of hidden behind the column toggle.
 *  - Row actions. These are read/route surfaces, so View/Edit link back
 *    to UserResource's own pages rather than each scoped resource
 *    growing a duplicate edit form. One canonical place to edit a user,
 *    several ways to find one.
 */
class RoleScopedUsersTable
{
    /**
     * @param  'student'|'instructor'  $role  Which lifecycle status column to show.
     */
    public static function configure(
        Table $table,
        string $role,
        string $emptyStateHeading,
        string $emptyStateDescription,
    ): Table {
        return UsersTable::configure($table)
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&color=ffffff&background=6366f1')
                    ->size(40),

                UserColumns::person(),

                UserColumns::mobile(),

                UserColumns::country(),

                TextColumn::make('profile.timezone')
                    ->label('Timezone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Two axes, never three: where this person is in their
                // role's lifecycle, and whether they can get into the
                // platform at all.
                $role === 'instructor'
                    ? UserColumns::instructorLifecycle()
                    : UserColumns::studentLifecycle(),

                UserColumns::accountAccess(),

                TextColumn::make('profile.profile_completion')
                    ->label('Profile')
                    ->suffix('%')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn ($record): bool => auth()->user()?->can('view', $record) ?? false)
                    ->url(fn ($record): string => UserResource::getUrl('view', ['record' => $record])),
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->visible(fn ($record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->url(fn ($record): string => UserResource::getUrl('edit', ['record' => $record])),
            ])
            // Destructive row/bulk actions stay on "All Users" only —
            // these lists exist to find a person, not to delete one.
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make(),
                ]),
            ])
            // No "Role" anywhere in the search copy — this list is already
            // one role, which is the whole point of it existing.
            ->header(view('filament.tables.user-search-bar', [
                'heading' => $role === 'instructor' ? 'Find an instructor' : 'Find a student',
                'placeholder' => sprintf(
                    'Type a name, email, mobile number, country or %s status…',
                    $role,
                ),
                'fields' => ['Name', 'Email', 'Mobile', 'Country', ucfirst($role).' status', 'Account status'],
            ]))
            ->searchPlaceholder(sprintf(
                'Search by name, email, mobile, country or %s status',
                $role,
            ))
            ->emptyStateHeading($emptyStateHeading)
            ->emptyStateDescription($emptyStateDescription);
    }
}
