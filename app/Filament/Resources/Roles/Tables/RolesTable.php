<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use App\Exceptions\CanonicalSuperAdminRoleProtectedException;
use App\Filament\Support\AdminDayRange;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\User;
use App\Services\Admin\RoleAuditRecorder;
use App\Services\Admin\SuperAdminGuardService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')
                    ->label('Role Name')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),

                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->badge()
                    ->color('primary'),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->badge()
                    ->color('info'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),

                Filter::make('created_at')
                    ->label('Created Date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->where('created_at', '>=', AdminDayRange::viewerDay($d)->startUtc))
                            ->when($data['to'], fn ($q, $d) => $q->where('created_at', '<', AdminDayRange::viewerDay($d)->endUtcExclusive));
                    }),
            ])

            ->recordActions([
                ViewAction::make(),

                EditAction::make(),

                ReplicateAction::make()
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    // permissions_count/users_count are ->counts() aggregates loaded onto
                    // the table's rows, not real columns — replicate() would otherwise try
                    // to INSERT them and fail with an unknown-column SQL error.
                    ->excludeAttributes(['permissions_count', 'users_count'])
                    ->form([
                        TextInput::make('name')
                            ->label('New Role Name')
                            ->required()
                            ->maxLength(100)
                            ->unique(table: 'roles', column: 'name'),
                    ])
                    ->beforeReplicaSaved(function (Role $record, array $data): void {
                        $record->name = $data['name'];
                        $record->description = $record->description
                            ? 'Copy of: '.$record->description
                            : null;
                        $record->status = 'inactive'; // duplicates start inactive
                    })
                    ->afterReplicaSaved(function (Role $replica, Role $original): void {
                        // Duplicating copies the ORIGINAL role's full permission set onto a
                        // new role — that's an assignment, not just a copy, so it must be
                        // gated by the same AssignPermissions:Role permission EditRole/
                        // CreateRole already require. Replicate:Role alone must not be a
                        // side-door around that gate.
                        $admin = auth()->user();
                        $copiedPermissions = [];

                        if ($admin?->can('AssignPermissions:Role')) {
                            $copiedPermissions = $original->permissions->pluck('name')->all();
                            $replica->syncPermissions($original->permissions);
                        }

                        if ($admin instanceof User) {
                            app(RoleAuditRecorder::class)->recordReplicated($admin, $original, $replica, $copiedPermissions, 'RolesTable.replicate');
                        }

                        Notification::make()
                            ->title('Role duplicated')
                            ->body("\"{$original->name}\" was duplicated as \"{$replica->name}\".")
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->action(function (Role $record): void {
                        try {
                            app(SuperAdminGuardService::class)->assertRoleNotCanonical($record);

                            $admin = auth()->user();

                            DB::transaction(function () use ($record, $admin): void {
                                if ($admin instanceof User) {
                                    app(RoleAuditRecorder::class)->recordDeleted($admin, $record, 'RolesTable.delete');
                                }

                                $record->delete();
                            });

                            Notification::make()->title('Role deleted')->success()->send();
                        } catch (CanonicalSuperAdminRoleProtectedException $e) {
                            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            $guard = app(SuperAdminGuardService::class);

                            try {
                                // Checked for
                                // every role in the selection BEFORE any
                                // deletion runs, so the canonical role being
                                // anywhere in the batch rejects the whole
                                // bulk action atomically.
                                $records->each(fn (Role $role) => $guard->assertRoleNotCanonical($role));
                            } catch (CanonicalSuperAdminRoleProtectedException $e) {
                                Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();

                                return;
                            }

                            $admin = auth()->user();
                            $recorder = app(RoleAuditRecorder::class);

                            // One audit event per role — keeps each event's
                            // subject/permission-count accurate and lets the
                            // Activity Log filter/find a specific role's
                            // deletion, rather than one opaque batch entry.
                            DB::transaction(function () use ($records, $admin, $recorder): void {
                                $records->each(function (Role $role) use ($admin, $recorder): void {
                                    if ($admin instanceof User) {
                                        $recorder->recordDeleted($admin, $role, 'RolesTable.bulkDelete');
                                    }

                                    $role->delete();
                                });
                            });

                            Notification::make()->title('Deleted')->success()->send();
                        }),
                    ExportBulkAction::make(),
                ]),
            ])

            ->defaultSort('name')

            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No roles yet')
            ->emptyStateDescription('Create your first role and assign permissions to it.')

            ->striped();

        return AdminListTable::apply($table);
    }
}
