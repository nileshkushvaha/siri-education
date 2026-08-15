<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Filament\Resources\Academic\CurriculumModuleResource;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Models\CurriculumModule;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditCurriculumModule extends EditRecord
{
    protected static string $resource = CurriculumModuleResource::class;

    /**
     * This resource has no [index] page by design (see CurriculumModuleResource
     * docblock) — default breadcrumbs try to link back to it via getResourceUrl(),
     * which throws. The "Back to Version" header action already provides the
     * equivalent navigation, so resource-level breadcrumbs are disabled here.
     */
    public function hasResourceBreadcrumbs(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToVersion')
                ->label('Back to Version')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => CurriculumVersionResource::getUrl('edit', ['record' => $this->record->curriculum_version_id])),
        ];
    }

    /** @param  CurriculumModule  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(CurriculumService::class)->updateModule(auth()->user(), $record, $data);
        } catch (CurriculumException $e) {
            Notification::make()->title('Module not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
