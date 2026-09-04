<?php

namespace App\Filament\Resources\Faq\Pages;

use App\Enums\FaqStatus;
use App\Filament\Resources\Faq\FaqResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\Faq;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListFaqs extends ListRecords
{
    protected static string $resource = FaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(Faq::class, FaqStatus::class);
    }
}
