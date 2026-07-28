<?php

namespace App\Filament\Resources\Faq\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Faq\FaqCategoryResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\Faq\FaqService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateFaqCategory extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = FaqCategoryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Create a category used to organize frequently asked questions.';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to FAQ Categories'),
        ]);
    }

    protected function afterCreate(): void
    {
        app(FaqService::class)->clearCache();
    }
}
