<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\Academic\InstructorSubjectTopicResource;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Models\InstructorSubjectTopic;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInstructorSubjectTopics extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorSubjectTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...array_filter([BackAction::toResourceIndex(SubjectResource::class, 'Back to Subjects')]),
            CreateAction::make(),
        ];
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::subjects();
    }

    public function getSubheading(): ?string
    {
        return 'Which topics each instructor teaches. Review pending declarations and approve them so they count towards matching.';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')->badge(fn (): int => InstructorSubjectTopic::query()->count()),
            'pending' => Tab::make('Pending approval')
                ->badge(fn (): int => InstructorSubjectTopic::query()->whereNull('approved_at')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('approved_at')),
            'approved' => Tab::make('Approved')
                ->badge(fn (): int => InstructorSubjectTopic::query()->whereNotNull('approved_at')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('approved_at')),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => InstructorSubjectTopic::query()->where('is_active', false)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }
}
