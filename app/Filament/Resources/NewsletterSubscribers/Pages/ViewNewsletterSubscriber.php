<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterSubscribers\Pages;

use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewNewsletterSubscriber extends ViewRecord
{
    protected static string $resource = NewsletterSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Newsletter Subscribers')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(NewsletterSubscriberResource::getUrl('index')),
        ];
    }
}
