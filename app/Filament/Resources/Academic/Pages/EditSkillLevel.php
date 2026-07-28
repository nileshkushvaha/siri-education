<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\SkillLevelResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSkillLevel extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = SkillLevelResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Skill Levels'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ]);
    }
}
