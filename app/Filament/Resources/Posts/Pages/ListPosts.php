<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PageStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\Post;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(Post::class, PageStatus::class);
    }
}
