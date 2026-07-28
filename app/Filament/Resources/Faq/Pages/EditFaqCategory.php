<?php

namespace App\Filament\Resources\Faq\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Faq\FaqCategoryResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\Faq\FaqService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditFaqCategory extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = FaqCategoryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Update how this FAQ category is presented and ordered.';
    }

    protected function afterSave(): void
    {
        app(FaqService::class)->clearCache();
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to FAQ Categories'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ]);
    }
}
