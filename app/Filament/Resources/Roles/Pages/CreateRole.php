<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Services\Admin\RoleAuditRecorder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<string> */
    public array $selectedPermissions = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // selectedPermissions is the Alpine-entangled Livewire property (see
        // permission-matrix.blade.php: $wire.entangle('selectedPermissions')) —
        // it is not part of $data, which only carries registered form fields.
        // Only honoured if the user has AssignPermissions:Role — a tampered
        // payload from a user without it is ignored, not just hidden in the UI.
        if (! $this->userCanAssignPermissions()) {
            $this->selectedPermissions = [];
        }

        // Only pass Spatie-fillable fields
        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterCreate(): void
    {
        /** @var Role $role */
        $role = $this->record;

        // Phase 24Q — GAP-011: the base Role model was already saved by
        // Filament's own handleRecordCreation() before this hook runs (not
        // atomic with what follows — this panel does not enable
        // ->databaseTransactions(), matching Phase 24E's finding for
        // EditUser). The extra-column save + permission sync + audit write
        // below are wrapped in one transaction so THAT sequence is atomic
        // with itself, even though it can't retroactively cover the
        // already-committed base save.
        DB::transaction(function () use ($role): void {
            // Set extra columns directly (bypasses fillable)
            $data = $this->data;
            $role->description = $data['description'] ?? null;
            $role->status = $data['status'] ?? 'active';
            $role->remarks = $data['remarks'] ?? null;
            $role->saveQuietly();

            // Sync permissions (empty unless the user has AssignPermissions:Role)
            $role->syncPermissions($this->selectedPermissions);

            $admin = auth()->user();
            if ($admin instanceof User) {
                app(RoleAuditRecorder::class)->recordCreated($admin, $role, $this->selectedPermissions, 'CreateRole');
            }
        });

        Notification::make()
            ->title('Role created')
            ->body("Role \"{$role->name}\" was created with ".count($this->selectedPermissions).' permissions.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function userCanAssignPermissions(): bool
    {
        return auth()->user()?->can('AssignPermissions:Role') ?? false;
    }
}
