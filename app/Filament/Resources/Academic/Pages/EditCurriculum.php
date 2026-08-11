<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\CurriculumResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\Curriculum;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditCurriculum extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = CurriculumResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Curricula'),
            DeleteAction::make(),
            RestoreAction::make(),
        ]);
    }

    /** @param  Curriculum  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(CurriculumService::class)->updateCurriculum(auth()->user(), $record, $data);
        } catch (CurriculumException $e) {
            Notification::make()->title('Curriculum not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
