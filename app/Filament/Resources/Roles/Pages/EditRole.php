<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Exceptions\CanonicalSuperAdminRoleProtectedException;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Services\Admin\RoleAuditRecorder;
use App\Services\Admin\SuperAdminGuardService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<string> Permission names selected in the matrix */
    public array $selectedPermissions = [];

    /** @var array<string> Snapshot of the role's permission names before this save. */
    private array $oldPermissions = [];

    private ?string $previousName = null;

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

                        $admin = auth()->user();

                        // Audit + delete wrapped together: they either both
                        // commit or both roll back. Log before deletion so
                        // the record still exists as the subject.
                        DB::transaction(function () use ($role, $admin): void {
                            if ($admin instanceof User) {
                                app(RoleAuditRecorder::class)->recordDeleted($admin, $role, 'EditRole');
                            }

                            $role->delete();
                        });

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
        // Snapshot BEFORE Filament's own handleRecordUpdate() applies $data
        // to the record — the audit diff needs the true pre-mutation name.
        $this->previousName = $this->record->name;
        $this->oldPermissions = $this->record->permissions->pluck('name')->all();

        // selectedPermissions is updated live by Alpine via $wire.selectedPermissions
        // Only pass Spatie-fillable fields to the model save
        $data = Arr::only($data, ['name', 'guard_name']);

        // Every authorization path recognizes
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

        DB::transaction(function () use ($role, $data): void {
            // Set extra columns directly
            $role->description = $data['description'] ?? null;
            $role->status = $data['status'] ?? 'active';
            $role->remarks = $data['remarks'] ?? null;
            $otherFieldsChanged = $role->isDirty(['description', 'status', 'remarks']);
            $role->saveQuietly();

            // Authoritative server-side guard — even a tampered Livewire payload
            // cannot change permissions without AssignPermissions:Role. The hidden
            // Section in RoleForm is UI convenience only, not the real boundary.
            if ($this->userCanAssignPermissions()) {
                $role->syncPermissions($this->selectedPermissions);
            }

            $admin = auth()->user();
            if ($admin instanceof User) {
                app(RoleAuditRecorder::class)->recordUpdated($admin, $role, $this->previousName, $this->oldPermissions, 'EditRole', $otherFieldsChanged);
            }
        });

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
