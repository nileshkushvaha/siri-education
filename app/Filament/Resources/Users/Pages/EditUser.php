<?php

namespace App\Filament\Resources\Users\Pages;

use App\Events\Auth\UserApproved;
use App\Exceptions\LastActiveSuperAdminException;
use App\Filament\Concerns\HasStudentLifecycleActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Admin\SuperAdminGuardService;
use App\Services\AuditTrailService;
use App\Services\Auth\PasswordHistoryService;
use App\Services\Student\StudentLifecycleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    use HasStudentLifecycleActions;

    protected static string $resource = UserResource::class;

    /** Snapshot of role names before the form is saved. */
    public array $oldRoles = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ...$this->studentLifecycleActions(),
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->id === auth()->id()
                    || app(SuperAdminGuardService::class)->isLastActiveSuperAdmin($this->record)
                )
                ->action(function (): void {
                    try {
                        app(SuperAdminGuardService::class)->protect($this->record, fn (User $user) => $user->delete());

                        Notification::make()->title('User deleted')->success()->send();

                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (LastActiveSuperAdminException $e) {
                        Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /**
     * Phase 24E — GAP-010/SRS-23-7: wraps Filament's ENTIRE save
     * lifecycle in the global Super Admin lifecycle lock, so a
     * concurrent save of a different Super Admin account can never
     * interleave with this one's check-then-write sequence. Always
     * released via finally, including when beforeSave() halts the
     * save.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $guard = app(SuperAdminGuardService::class);
        $guard->acquireLock();

        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } finally {
            $guard->releaseLock();
        }
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

        $this->assertSuperAdminInvariantForSubmittedData();
    }

    /**
     * Phase 24E — GAP-010/SRS-23-7: this panel does not enable
     * per-page database transactions (Filament\Pages\Concerns\
     * CanUseDatabaseTransactions::hasDatabaseTransactions() is false
     * here), so a role/status change already applied by Filament's own
     * saveRelationships()/handleRecordUpdate() cannot be rolled back
     * afterward — checking in afterSave() would be too late. The
     * check must run HERE, against the SUBMITTED (not yet applied)
     * form data, and halt the whole save before anything is written if
     * it would leave zero active Super Admins.
     */
    private function assertSuperAdminInvariantForSubmittedData(): void
    {
        $guard = app(SuperAdminGuardService::class);

        if (! $guard->isActiveSuperAdmin($this->record)) {
            return;
        }

        $submittedRoleIds = $this->data['roles'] ?? null;
        $submittedStatus = $this->data['status'] ?? $this->record->status;

        $wouldStillHaveRole = $submittedRoleIds === null
            || Role::query()->whereIn('id', $submittedRoleIds)->where('name', SuperAdminGuardService::SUPER_ADMIN_ROLE)->exists();

        if ($wouldStillHaveRole && $submittedStatus === User::STATUS_ACTIVE) {
            return;
        }

        if ($guard->countOtherActiveSuperAdmins($this->record) > 0) {
            return;
        }

        Notification::make()->title('Action failed')->body(LastActiveSuperAdminException::make()->getMessage())->danger()->send();
        $this->halt();
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

        // Phase 24H.1A — GAP-013: this panel doesn't wrap the whole save
        // in one DB transaction (see the docblock on save() above), so
        // Filament's own role sync and this initialization aren't one
        // atomic unit. That's acceptable here specifically because the
        // failure mode is fail-closed either way: isEligibleForStudentActions()
        // requires student_status === Active, so a role granted without
        // (yet) a governed status still correctly denies student
        // capability rather than granting it.
        if (in_array('student', $added, true)) {
            app(StudentLifecycleService::class)->initializeStudentRoleIfNeeded($fresh, $admin);
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
