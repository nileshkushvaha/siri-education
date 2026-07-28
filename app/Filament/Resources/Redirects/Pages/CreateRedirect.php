<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Content\Redirects\Exceptions\RedirectException;
use App\Content\Redirects\Services\RedirectService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateRedirect extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Redirects'),
        ]);
    }

    /** Creation flows through the service — normalized, validated, audited. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(RedirectService::class)->create(auth()->user(), [
                'source_path' => $data['source_path'],
                'target_path' => $data['target_path'],
                'type' => $data['type'],
                'description' => $data['description'] ?? null,
            ]);
        } catch (RedirectException $e) {
            Notification::make()->title('Redirect not created')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
