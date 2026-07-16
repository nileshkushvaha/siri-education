<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;
use App\Reporting\Filters\ReportFilterKey;

/**
 * The single place that decides what an administrator may do with the
 * reporting layer (Phase 18B §13). Permissions are always the source of
 * truth; every method denies safely (`false`) rather than throwing when
 * context is incomplete, so a caller must always check before acting —
 * nothing here grants access merely because a report definition exists
 * or is listed somewhere.
 */
interface ReportAccessContextInterface
{
    public function canView(User $user, ReportDefinition $definition): bool;

    public function canExport(User $user, ReportDefinition $definition): bool;

    public function canPerformSensitiveExport(User $user): bool;

    public function canViewFinancialValues(User $user): bool;

    public function canViewInstructorCompensation(User $user): bool;

    public function canViewFullStudentIdentity(User $user): bool;

    public function shouldMaskPersonalData(User $user): bool;

    public function canAccessArchivedEntities(User $user): bool;

    public function canUseFilter(User $user, ReportDefinition $definition, ReportFilterKey $key): bool;

    public function canViewCategory(User $user, ReportCategory $category): bool;
}
