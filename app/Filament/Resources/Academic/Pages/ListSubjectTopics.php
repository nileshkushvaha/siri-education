<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Resources\Academic\SubjectTopicResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Models\SubjectTopic;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListSubjectTopics extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = SubjectTopicResource::class;

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
        return 'Topics break each subject into teachable units. Filter by subject or type, or group the list by subject to review a whole catalogue at once.';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return AcademicStatusTabs::make(SubjectTopic::class);
    }
}
