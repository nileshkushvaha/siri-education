<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\AcademicLevelResource;
use App\Filament\Resources\Academic\SkillLevelResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicLevels extends ListRecords
{
    protected static string $resource = AcademicLevelResource::class;

    public function getSubheading(): ?string
    {
        return 'Academic levels are broad education stages. Use Skill Levels only for proficiency labels such as Beginner or Advanced.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('skillLevels')->label('Skill Levels')->url(SkillLevelResource::getUrl())->visible(SkillLevelResource::canViewAny()),
            CreateAction::make(),
        ];
    }
}
