<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\PasswordPolicySettings;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = UserResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Users'),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['password_confirmation']);

        if (app(PasswordPolicySettings::class)->force_change_on_first_login) {
            $data['must_change_password'] = true;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record->must_change_password) {
            activity('users')
                ->performedOn($this->record)
                ->causedBy(auth()->user())
                ->event('password_change_required')
                ->log('Password change required on first login');
        }

        // Role assignment happens via Filament's own
        // relationship field (UserForm's roles Select) before this hook
        // runs — mirrors EditUser::afterSave()'s 'roles_updated' shape so
        // both surfaces are filterable/comparable the same way in the
        // Activity Log. A brand-new user always has "added" roles only
        // (there is no "before" state on create); skipped entirely if no
        // role was assigned.
        $admin = auth()->user();
        $initialRoles = $this->record->roles->pluck('name')->all();

        if ($admin instanceof User && $initialRoles !== []) {
            app(AuditTrailService::class)->logUser($admin, 'users', 'roles_updated', 'User roles assigned on creation', $this->record, [
                'roles_added' => $initialRoles,
                'roles_removed' => [],
                'current_roles' => $initialRoles,
            ]);
        }

        // A brand-new user created directly
        // through Filament (not the registration flow) with the student
        // role checked would otherwise be left with student_status null
        // forever — RegistrationService is the only path that normally
        // sets it, and this page doesn't go through that flow.
        if ($this->record->hasRole('student')) {
            $admin = auth()->user();
            app(StudentLifecycleService::class)->initializeStudentRoleIfNeeded(
                $this->record,
                $admin instanceof User ? $admin : null,
            );
        }
    }
}
