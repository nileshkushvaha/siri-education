<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\InstructorSubjectTopicResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInstructorSubjectTopic extends CreateRecord
{
    protected static string $resource = InstructorSubjectTopicResource::class;

    /** Map the form's "approved" toggle onto approved_at/approved_by. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($this->form->getRawState()['approved'] ?? false) {
            $data['approved_at'] = now();
            $data['approved_by'] = auth()->id();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
