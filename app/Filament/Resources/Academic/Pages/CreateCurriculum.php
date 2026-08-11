<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\CurriculumResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateCurriculum extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = CurriculumResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Curricula'),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CurriculumService::class)->createCurriculum(auth()->user(), $data);
        } catch (CurriculumException $e) {
            Notification::make()->title('Curriculum not created')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
