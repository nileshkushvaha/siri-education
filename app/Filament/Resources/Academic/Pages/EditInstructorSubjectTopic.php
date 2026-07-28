<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\InstructorSubjectTopicResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\EditRecord;

class EditInstructorSubjectTopic extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = InstructorSubjectTopicResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Instructor Topic Coverage'),
        ]);
    }

    /** Map the form's "approved" toggle onto approved_at/approved_by, preserving the original approval time. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $approved = (bool) ($this->form->getRawState()['approved'] ?? false);

        if ($approved && $this->record->approved_at === null) {
            $data['approved_at'] = now();
            $data['approved_by'] = auth()->id();
        }

        if (! $approved) {
            $data['approved_at'] = null;
            $data['approved_by'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
