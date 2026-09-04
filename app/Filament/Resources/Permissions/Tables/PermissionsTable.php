<?php

declare(strict_types=1);

namespace App\Filament\Resources\Permissions\Tables;

use App\Filament\Support\Tables\AdminListTable;
use App\Models\User;
use App\Services\Admin\PermissionAuditRecorder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            $admin = auth()->user();
                            $recorder = app(PermissionAuditRecorder::class);

                            // Whole batch shares one transaction: if any
                            // deletion in the batch fails, everything
                            // (including audit rows already written this
                            // batch) rolls back — no partial-success audit
                            // trail for a batch that didn't fully commit,
                            // matching DeleteBulkAction's own all-or-nothing
                            // default behavior (it doesn't support partial
                            // per-record success either).
                            DB::transaction(function () use ($records, $admin, $recorder): void {
                                $records->each(function (Permission $permission) use ($admin, $recorder): void {
                                    $affectedRoleNames = $permission->roles()->pluck('name')->all();

                                    if ($admin instanceof User) {
                                        $recorder->recordDeleted($admin, $permission, $affectedRoleNames, 'PermissionsTable.bulkDelete');
                                    }

                                    $permission->delete();
                                });
                            });

                            Notification::make()->title('Deleted')->success()->send();
                        }),
                ]),
            ]);

        return AdminListTable::apply($table);
    }
}
