<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReviewTags\Pages;

use App\Filament\Resources\ReviewTags\ReviewTagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReviewTags extends ListRecords
{
    protected static string $resource = ReviewTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
