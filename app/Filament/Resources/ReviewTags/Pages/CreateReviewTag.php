<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReviewTags\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\ReviewTags\ReviewTagResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateReviewTag extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = ReviewTagResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Review Tags'),
        ]);
    }
}
