<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\InstructorStatus;
use App\Exceptions\LastActiveSuperAdminException;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use App\Services\Admin\SuperAdminGuardService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['profile.media']))
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&color=ffffff&background=6366f1')
                    ->size(40),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Email copied'),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->separator(','),

                TextColumn::make('authored_posts_count')
                    ->label('Posts')
                    ->counts('authoredPosts'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('profile.instructor_status')
                    ->label('Instructor')
                    ->badge()
                    ->formatStateUsing(fn (?InstructorStatus $state): string => $state?->label() ?? '—')
                    ->color(fn (?InstructorStatus $state): string => $state?->color() ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Verified' : 'Unverified')
                    ->color(fn ($state): string => $state ? 'success' : 'warning')
                    ->sortable(),

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

            ->filters([
                SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),

                SelectFilter::make('instructor_status')
                    ->label('Instructor Status')
                    ->options(InstructorStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->where('instructor_status', $value));
                    }),

                TernaryFilter::make('email_verified_at')
                    ->label('Email Verified')
                    ->nullable()
                    ->trueLabel('Verified')
                    ->falseLabel('Unverified')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])

            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Phase 14.2 — jump to the instructor's compensation
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
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            // Self-deletion (of the acting admin's own account)
                            // is excluded regardless of Super Admin status —
                            // a pre-existing, unrelated safety net, not this
                            // phase's invariant.
                            $targets = $records->reject(fn ($record) => $record->id === auth()->id());

                            try {
                                // Phase 24E — GAP-010/SRS-23-7: the whole
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

            ->defaultSort('created_at', 'desc')

            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Create your first user to get started.')

            ->striped();
    }
}
