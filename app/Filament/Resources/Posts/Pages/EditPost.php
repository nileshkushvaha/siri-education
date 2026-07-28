<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\PostService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PostResource::class;

    protected function afterSave(): void
    {
        app(PostService::class)->refreshReadingTime($this->record);
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Posts'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ]);
    }
}
