<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Phase 18B — the permission-controlled reporting landing page (§18).
 * A simple catalogue: for every category the current administrator may
 * see, lists the reports already available (with a real link) and the
 * reports still planned for a later Phase 18 slice (no link, no faked
 * data). Rendering this page never executes a report query — it only
 * reads code-defined metadata from `ReportRegistryInterface`.
 *
 * This does not replace `BookingReports` or `ReviewsQualityDashboard` —
 * both remain separate pages this hub simply links to.
 */
class ReportingHub extends Page
{
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.reporting-hub';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Reporting Hub';

    protected static ?string $title = 'Reporting Hub';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $access = app(ReportAccessContextInterface::class);

        foreach (ReportCategory::cases() as $category) {
            if ($access->canViewCategory($user, $category)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{category: ReportCategory, available: Collection<int, ReportDefinition>, planned: Collection<int, ReportDefinition>}>
     */
    public function categories(): array
    {
        $user = auth()->user();
        $access = app(ReportAccessContextInterface::class);
        $registry = app(ReportRegistryInterface::class);
        $all = collect($registry->all());

        return collect(ReportCategory::cases())
            ->filter(fn (ReportCategory $category): bool => $access->canViewCategory($user, $category))
            ->map(function (ReportCategory $category) use ($all, $access, $user): array {
                $inCategory = $all->filter(fn (ReportDefinition $d): bool => $d->category === $category);

                return [
                    'category' => $category,
                    'available' => $inCategory
                        ->filter(fn (ReportDefinition $d): bool => $d->available && $access->canView($user, $d))
                        ->values(),
                    'planned' => $inCategory->reject(fn (ReportDefinition $d): bool => $d->available)->values(),
                ];
            })
            ->values()
            ->all();
    }

    public function reportUrl(ReportDefinition $definition): ?string
    {
        if ($definition->routeName === null || ! class_exists($definition->routeName)) {
            return null;
        }

        return $definition->routeName::getUrl();
    }
}
