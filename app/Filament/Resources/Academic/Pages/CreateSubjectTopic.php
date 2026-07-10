<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\SubjectTopicResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubjectTopic extends CreateRecord
{
    protected static string $resource = SubjectTopicResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
