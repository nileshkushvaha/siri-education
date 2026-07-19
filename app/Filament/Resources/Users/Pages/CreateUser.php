<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use App\Settings\PasswordPolicySettings;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

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

        // Phase 24H.1A — GAP-013: a brand-new user created directly
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
