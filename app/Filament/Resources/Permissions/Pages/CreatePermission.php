<?php

declare(strict_types=1);

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use App\Models\User;
use App\Services\Admin\PermissionAuditRecorder;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    /**
     * Phase 24Q.1 — GAP-011: AppServiceProvider::registerPermissionObserver()
     * already auto-grants every newly created Permission to super_admin via
     * a Permission::created listener, which fires synchronously during the
     * base create() call above this hook — by the time afterCreate() runs,
     * that grant (if it happened) has already been applied. One event
     * records both facts (created + whether the auto-grant applied) rather
     * than a second, misleading event for the same logical operation.
     */
    protected function afterCreate(): void
    {
        /** @var Permission $permission */
        $permission = $this->record;

        $admin = auth()->user();
        if (! $admin instanceof User) {
            return;
        }

        $autoGranted = Role::where('name', 'super_admin')->first()?->hasPermissionTo($permission) ?? false;

        app(PermissionAuditRecorder::class)->recordCreated($admin, $permission, $autoGranted, 'CreatePermission');
    }
}
