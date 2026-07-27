<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Models\User;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;
use App\Reporting\Filters\ReportFilterKey;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Permission-driven implementation of {@see ReportAccessContextInterface}.
 * Mirrors `App\Quality\Support\QualityDashboardAccess`'s exact
 * super-admin-bypass + `hasPermissionTo()` pattern — `hasPermissionTo()`
 * is a Spatie method, not routed through Laravel's `Gate`, so the
 * global `Gate::before` super-admin bypass never reaches it; the
 * explicit `isSuperAdmin()` check here is what closes that gap.
 */
final class ReportAccessContext implements ReportAccessContextInterface
{
    public function canView(User $user, ReportDefinition $definition): bool
    {
        return $definition->available
            && $this->canViewCategory($user, $definition->category)
            && $this->hasPermission($user, $definition->requiredViewPermission);
    }

    public function canExport(User $user, ReportDefinition $definition): bool
    {
        if ($definition->requiredExportPermission === null || ! $definition->exportAvailable) {
            return false;
        }

        // View permission never implies export, but export always requires view too.
        return $this->canView($user, $definition)
            && $this->hasPermission($user, $definition->requiredExportPermission);
    }

    public function canPerformSensitiveExport(User $user): bool
    {
        return $this->hasPermission($user, 'ExportSensitiveReports');
    }

    public function canViewFinancialValues(User $user): bool
    {
        return $this->hasPermission($user, 'ViewFinanceReports');
    }

    public function canViewInstructorCompensation(User $user): bool
    {
        // Deliberately independent of canViewFinancialValues() — instructor
        // compensation access must remain more restrictive than general
        // financial reporting (SRS §12), so it is never implied by
        // ViewFinanceReports alone.
        return $this->hasPermission($user, 'ViewInstructorCompensationReports');
    }

    public function canViewFullStudentIdentity(User $user): bool
    {
        return $this->hasPermission($user, 'ViewStudentReports');
    }

    public function shouldMaskPersonalData(User $user): bool
    {
        return ! $this->canViewFullStudentIdentity($user);
    }

    public function canAccessArchivedEntities(User $user): bool
    {
        // Historical reporting principle (SRS §15): archived/soft-
        // deleted entities remain usable in historical reports by design
        // — this is a capability flag for report queries to consult, not
        // an additional permission gate on top of report-view access.
        return true;
    }

    public function canUseFilter(User $user, ReportDefinition $definition, ReportFilterKey $key): bool
    {
        return in_array($key, $definition->supportedFilters, true);
    }

    public function canViewCategory(User $user, ReportCategory $category): bool
    {
        return $this->hasPermission($user, $category->requiredPermission());
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
