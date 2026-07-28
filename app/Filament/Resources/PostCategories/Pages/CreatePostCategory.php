<?php

namespace App\Filament\Resources\PostCategories\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\PostCategories\PostCategoryResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreatePostCategory extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PostCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Categories'),
        ]);
    }
}
