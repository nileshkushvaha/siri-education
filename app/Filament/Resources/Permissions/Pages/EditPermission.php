<?php

declare(strict_types=1);

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\User;
use App\Services\Admin\PermissionAuditRecorder;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class EditPermission extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PermissionResource::class;

    private ?string $previousName = null;

    private ?string $previousGuardName = null;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Permissions'),
            DeleteAction::make()
                ->action(function (): void {
                    /** @var Permission $permission */
                    $permission = $this->record;

                    $admin = auth()->user();

                    // Captured BEFORE deletion — role_has_permissions pivot
                    // rows disappear with the permission.
                    $affectedRoleNames = $permission->roles()->pluck('name')->all();

                    DB::transaction(function () use ($permission, $admin, $affectedRoleNames): void {
                        if ($admin instanceof User) {
                            app(PermissionAuditRecorder::class)->recordDeleted($admin, $permission, $affectedRoleNames, 'EditPermission');
                        }

                        $permission->delete();
                    });

                    Notification::make()->title('Permission deleted')->success()->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Snapshot BEFORE Filament's own handleRecordUpdate() applies $data.
        $this->previousName = $this->record->name;
        $this->previousGuardName = $this->record->guard_name;

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Permission $permission */
        $permission = $this->record;

        $admin = auth()->user();
        if (! $admin instanceof User) {
            return;
        }

        app(PermissionAuditRecorder::class)->recordUpdated($admin, $permission, $this->previousName, $this->previousGuardName, 'EditPermission');
    }
}
