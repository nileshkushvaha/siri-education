<?php

namespace App\Filament\Resources\Faq\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Faq\FaqResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\Faq\FaqService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditFaq extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = FaqResource::class;

    protected function afterSave(): void
    {
        app(FaqService::class)->clearCache();
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to FAQs'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ]);
    }
}
