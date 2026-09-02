<?php

namespace App\Filament\Resources\Users\Tables;

use App\Exceptions\LastActiveSuperAdminException;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use App\Services\Admin\SuperAdminGuardService;
use App\Services\Admin\UserDeletionGuard;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['profile.media', 'profile.country']))

            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&color=ffffff&background=6366f1')
                    ->size(40),

                UserColumns::person(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->separator(','),

                UserColumns::mobile(),

                UserColumns::country(),

                UserColumns::accountAccess(),

                // Both lifecycle columns are available here but off by
                // default — this list mixes every role, so neither applies
                // to most rows. The Students / Instructors lists show the
                // one that matters outright.
                UserColumns::instructorLifecycle()
                    ->toggleable(isToggledHiddenByDefault: true),

                UserColumns::studentLifecycle()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('profile.profile_completion')
                    ->label('Profile')
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Jump to the instructor's compensation
                // agreements (amounts are managed there, never here).
                Action::make('manage_compensation')
                    ->label('Manage Compensation')
                    ->icon('heroicon-m-briefcase')
                    ->color('gray')
                    ->visible(fn ($record): bool => $record->hasRole('instructor')
                        && (auth()->user()?->can('viewAny', InstructorCompensationAgreement::class) ?? false))
                    ->url(fn ($record): string => InstructorCompensationAgreementResource::getUrl(parameters: [
                        'tableFilters' => ['instructor_id' => ['value' => $record->id]],
                    ])),
                DeleteAction::make()
                    ->hidden(fn ($record): bool => $record->id === auth()->id()
                        || app(SuperAdminGuardService::class)->isLastActiveSuperAdmin($record)
                    )
                    ->action(function ($record): void {
                        try {
                            app(SuperAdminGuardService::class)->protect($record, fn (User $user) => $user->delete());

                            Notification::make()->title('User deleted')->success()->send();
                        } catch (LastActiveSuperAdminException $e) {
                            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                        }
                    }),

                RestoreAction::make()
                    ->successNotificationTitle('Account restored'),

                // Mirrors EditUser: refuse with a readable reason before
                // attempting, so a 1451 never becomes a generic page error.
                ForceDeleteAction::make()
                    ->hidden(fn ($record): bool => $record->id === auth()->id()
                        || app(SuperAdminGuardService::class)->isLastActiveSuperAdmin($record)
                    )
                    ->action(function ($record): void {
                        $guard = app(UserDeletionGuard::class);

                        if (! $guard->canForceDelete($record)) {
                            Notification::make()
                                ->title('This account cannot be permanently deleted')
                                ->body($guard->refusalMessage($record))
                                ->danger()->persistent()->send();

                            return;
                        }

                        try {
                            app(SuperAdminGuardService::class)->protect($record, fn (User $user) => $user->forceDelete());

                            Notification::make()->title('Account permanently deleted')->success()->send();
                        } catch (LastActiveSuperAdminException $e) {
                            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                        } catch (QueryException $e) {
                            report($e);

                            Notification::make()
                                ->title('This account cannot be permanently deleted')
                                ->body($guard->refusalMessage($record)
                                    ?: 'It still has linked records that must be kept. The account remains deleted and hidden.')
                                ->danger()->persistent()->send();
                        }
                    }),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            // Self-deletion (of the acting admin's own account)
                            // is excluded regardless of Super Admin status —
                            // a separate, unrelated safety net.
                            $targets = $records->reject(fn ($record) => $record->id === auth()->id());

                            try {
                                // SRS-23-7: the whole
                                // selection is evaluated as ONE proposed
                                // mutation set. If deleting everyone in it
                                // would leave zero active Super Admins, the
                                // entire bulk delete is rejected atomically
                                // — never just the row that trips the check.
                                app(SuperAdminGuardService::class)->protectBatch($targets, fn (User $user) => $user->delete());

                                Notification::make()
                                    ->title('Deleted')
                                    ->body('Selected users have been deleted.')
                                    ->success()
                                    ->send();
                            } catch (LastActiveSuperAdminException $e) {
                                Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    ExportBulkAction::make(),
                ]),
            ])

            // One search box instead of a filter panel: the old five
            // filters were a dropdown-per-question (role, status,
            // instructor status, student status, verified) for what is
            // almost always "find this one person". Every visible column
            // is searchable — including mobile, country, role name and
            // the account status *labels* — so typing "pending",
            // "instructor" or a phone number narrows the list the same
            // way picking a filter used to, without the extra clicks.
            ->header(view('filament.tables.user-search-bar', [
                'heading' => 'Find a user',
                'placeholder' => 'Type a name, email, mobile number, country, role or status…',
                'fields' => ['Name', 'Email', 'Mobile', 'Country', 'Role', 'Account status'],
            ]))
            ->searchPlaceholder('Search by name, email, mobile, role, country or status')
            ->searchDebounce('400ms')
            ->persistSearchInSession()

            ->defaultSort('created_at', 'desc')

            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Create your first user to get started.')

            ->striped();
    }
}
