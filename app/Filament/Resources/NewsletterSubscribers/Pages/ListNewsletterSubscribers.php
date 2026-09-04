<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterSubscribers\Pages;

use App\Enums\NewsletterSubscriberStatus;
use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\NewsletterSubscriber;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListNewsletterSubscribers extends ListRecords
{
    protected static string $resource = NewsletterSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(NewsletterSubscriber::class, NewsletterSubscriberStatus::class);
    }
}
