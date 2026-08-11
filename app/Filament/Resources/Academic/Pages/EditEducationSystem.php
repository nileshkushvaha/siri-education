<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\EducationSystemResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\EducationSystem;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditEducationSystem extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = EducationSystemResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Education Systems'),
            DeleteAction::make(),
            RestoreAction::make(),
        ]);
    }

    /** @param  EducationSystem  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(EducationSystemService::class)->updateEducationSystem(auth()->user(), $record, $data);
        } catch (AcademicContextException $e) {
            Notification::make()->title('Education system not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
