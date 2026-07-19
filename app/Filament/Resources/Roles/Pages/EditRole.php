<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Exceptions\CanonicalSuperAdminRoleProtectedException;
use App\Filament\Resources\Roles\RoleResource;
use App\Services\Admin\SuperAdminGuardService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<string> Permission names selected in the matrix */
    public array $selectedPermissions = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->name === SuperAdminGuardService::SUPER_ADMIN_ROLE)
                ->action(function (): void {
                    try {
                        /** @var Role $role */
                        $role = $this->record;

                        app(SuperAdminGuardService::class)->assertRoleNotCanonical($role);

                        // Log before deletion so the record still exists as the subject.
                        activity('roles')
                            ->performedOn($role)
                            ->causedBy(auth()->user())
                            ->event('deleted')
                            ->withProperties([
                                'name' => $role->name,
                                'permissions_count' => $role->permissions->count(),
                            ])
                            ->log('Role deleted');

                        $role->delete();

                        Notification::make()->title('Role deleted')->success()->send();

                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (CanonicalSuperAdminRoleProtectedException $e) {
                        Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Only expose the role's current permission list to the browser if the
        // user is allowed to see/change it — otherwise leave the matrix empty.
        if ($this->userCanAssignPermissions()) {
            $this->selectedPermissions = $this->record->permissions->pluck('name')->toArray();
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // selectedPermissions is updated live by Alpine via $wire.selectedPermissions
        // Only pass Spatie-fillable fields to the model save
        $data = Arr::only($data, ['name', 'guard_name']);

        // Phase 24E — GAP-010/SRS-23-7: every authorization path recognizes
        // Super Admin access by this exact role NAME (Gate::before(),
        // User::isSuperAdmin(), PortalResolver) — renaming it away would
        // silently strip access from every Super Admin at once.
        try {
            app(SuperAdminGuardService::class)->assertCanonicalRoleNameUnchanged($this->record, $data['name'] ?? $this->record->name);
        } catch (CanonicalSuperAdminRoleProtectedException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Role $role */
        $role = $this->record;

        $data = $this->data;

        // Set extra columns directly
        $role->description = $data['description'] ?? null;
        $role->status = $data['status'] ?? 'active';
        $role->remarks = $data['remarks'] ?? null;
        $role->saveQuietly();

        // Authoritative server-side guard — even a tampered Livewire payload
        // cannot change permissions without AssignPermissions:Role. The hidden
        // Section in RoleForm is UI convenience only, not the real boundary.
        if ($this->userCanAssignPermissions()) {
            $oldPermissions = $role->permissions->pluck('name')->toArray();

            $role->syncPermissions($this->selectedPermissions);

            $added = array_diff($this->selectedPermissions, $oldPermissions);
            $removed = array_diff($oldPermissions, $this->selectedPermissions);

            activity('roles')
                ->performedOn($role)
                ->causedBy(auth()->user())
                ->event('updated')
                ->withProperties([
                    'permissions_added' => array_values($added),
                    'permissions_removed' => array_values($removed),
                    'total_permissions' => count($this->selectedPermissions),
                ])
                ->log('Role updated');
        } else {
            activity('roles')
                ->performedOn($role)
                ->causedBy(auth()->user())
                ->event('updated')
                ->log('Role updated');
        }

        Notification::make()
            ->title('Role saved')
            ->body("Role \"{$role->name}\" updated.")
            ->success()
            ->send();
    }

    private function userCanAssignPermissions(): bool
    {
        return auth()->user()?->can('AssignPermissions:Role') ?? false;
    }
}
