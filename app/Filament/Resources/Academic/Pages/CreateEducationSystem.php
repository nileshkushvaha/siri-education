<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\EducationSystemResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateEducationSystem extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = EducationSystemResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Education Systems'),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EducationSystemService::class)->createEducationSystem(auth()->user(), $data);
        } catch (AcademicContextException $e) {
            Notification::make()->title('Education system not created')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
