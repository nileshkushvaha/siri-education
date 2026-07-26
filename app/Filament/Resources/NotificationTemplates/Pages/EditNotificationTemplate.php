<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationTemplateService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * GAP-039 — routes every save through NotificationTemplateService so
 * content edits are versioned, audited, and cache-busted; never a bare
 * Eloquent ->save() that would bypass all three.
 */
class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var NotificationTemplate $record */
        return app(NotificationTemplateService::class)->updateOverride(
            $record,
            auth()->user(),
            $data['subject'] ?? null,
            $data['body'] ?? null,
        );
    }
}
