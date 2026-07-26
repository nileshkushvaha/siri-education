<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
