<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Models\Subject;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListSubjects extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = SubjectResource::class;

    public function getSubheading(): ?string
    {
        return 'Manage the subject catalogue here. Categories, topics, and instructor coverage remain available as related tools.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::subjects();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return AcademicStatusTabs::make(Subject::class);
    }
}
