<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\PostService;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Posts'),
        ]);
    }

    protected function afterCreate(): void
    {
        app(PostService::class)->refreshReadingTime($this->record);
    }
}
