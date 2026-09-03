<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Filament\Resources\Academic\CurriculumResource;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\CurriculumVersion;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCurriculumVersions extends ListRecords
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(CurriculumResource::class, 'Back to Curricula'),
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Every version across all curricula. Open a version to manage its modules and move it through draft, published, archived and retired.';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')->badge(fn (): int => CurriculumVersion::query()->count())];

        foreach (CurriculumVersionStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge(fn (): int => CurriculumVersion::query()->where('status', $status)->count())
                ->badgeColor($status->color())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));
        }

        return $tabs;
    }
}
