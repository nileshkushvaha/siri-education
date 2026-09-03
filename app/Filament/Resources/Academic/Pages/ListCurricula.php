<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Filament\Resources\Academic\CurriculumResource;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Models\Curriculum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCurricula extends ListRecords
{
    protected static string $resource = CurriculumResource::class;

    public function getSubheading(): ?string
    {
        return 'Choose a curriculum to manage its versions and structured content. Draft and historical versions stay inside the curriculum workflow.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('versions')->label('All Versions')->url(CurriculumVersionResource::getUrl())->visible(CurriculumVersionResource::canViewAny()),
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $published = fn (Builder $query): Builder => $query->whereHas('versions', fn (Builder $q) => $q->where('status', CurriculumVersionStatus::Published));
        $unpublished = fn (Builder $query): Builder => $query->whereDoesntHave('versions', fn (Builder $q) => $q->where('status', CurriculumVersionStatus::Published));

        return [
            'all' => Tab::make('All')->badge(fn (): int => Curriculum::query()->count()),
            'published' => Tab::make('Published')
                ->badge(fn (): int => $published(Curriculum::query())->count())
                ->badgeColor('success')
                ->modifyQueryUsing($published),
            'unpublished' => Tab::make('Not yet published')
                ->badge(fn (): int => $unpublished(Curriculum::query())->count())
                ->badgeColor('warning')
                ->modifyQueryUsing($unpublished),
            'deleted' => Tab::make('Deleted')
                ->badge(fn (): int => Curriculum::query()->onlyTrashed()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed()),
        ];
    }
}
