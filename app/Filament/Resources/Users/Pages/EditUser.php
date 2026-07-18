<?php

namespace App\Filament\Resources\Users\Pages;

use App\Events\Auth\UserApproved;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Auth\PasswordHistoryService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Snapshot of role names before the form is saved. */
    public array $oldRoles = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->id === auth()->id()
                    || $this->record->isSuperAdmin()
                ),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Do not update password if left blank during edit
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // password_confirmation is a virtual field only used for validation
        unset($data['password_confirmation']);

        return $data;
    }

    protected bool $wasMustChangePassword = false;

    protected string $previousPasswordHash = '';

    protected string $previousStatus = '';

    protected function beforeSave(): void
    {
        $this->oldRoles = $this->record->roles->pluck('name')->toArray();
        $this->wasMustChangePassword = (bool) $this->record->must_change_password;
        $this->previousPasswordHash = $this->record->password ?? '';
        $this->previousStatus = $this->record->status ?? '';
    }

    protected function afterSave(): void
    {
        $fresh = $this->record->fresh();
        $newRoles = $fresh->roles->pluck('name')->toArray();

        // This page is only reachable by an authenticated admin; the guard
        // is purely for AuditTrailService::logUser()'s non-nullable signature.
        $admin = auth()->user();
        if (! $admin instanceof User) {
            return;
        }

        $audit = app(AuditTrailService::class);

        $added = array_values(array_diff($newRoles, $this->oldRoles));
        $removed = array_values(array_diff($this->oldRoles, $newRoles));

        if ($added || $removed) {
            $audit->logUser($admin, 'users', 'roles_updated', 'User roles updated', $this->record, [
                'roles_added' => $added,
                'roles_removed' => $removed,
                'current_roles' => $newRoles,
            ]);
        }

        // If admin set a new password, store old hash and reset the expiry clock
        if ($this->previousPasswordHash && $fresh->password !== $this->previousPasswordHash) {
            app(PasswordHistoryService::class)->store($this->record, $this->previousPasswordHash);
            $this->record->update(['password_changed_at' => now()]);
        }

        // Detect approval: admin changed status from INACTIVE (pending approval) → ACTIVE
        if ($this->previousStatus === User::STATUS_INACTIVE && $fresh->status === User::STATUS_ACTIVE) {
            UserApproved::dispatch($fresh, $admin);

            $audit->logUser($admin, 'users', 'account_approved', 'User account approved by administrator', $this->record);
        }

        $isMustChange = (bool) $fresh->must_change_password;

        if ($isMustChange && ! $this->wasMustChangePassword) {
            $audit->logUser($admin, 'users', 'password_change_required', 'Administrator required password change on next login', $this->record);
        } elseif (! $isMustChange && $this->wasMustChangePassword) {
            $audit->logUser($admin, 'users', 'password_change_cleared', 'Password change requirement cleared by administrator', $this->record);
        }
    }
}
