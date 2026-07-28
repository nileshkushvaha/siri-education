<?php

namespace App\Filament\Resources\Faq\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Faq\FaqResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\Faq\FaqService;
use Filament\Resources\Pages\CreateRecord;

class CreateFaq extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = FaqResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to FAQs'),
        ]);
    }

    protected function afterCreate(): void
    {
        app(FaqService::class)->clearCache();
    }
}
