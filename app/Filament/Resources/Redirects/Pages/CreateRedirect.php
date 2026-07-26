<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Content\Redirects\Exceptions\RedirectException;
use App\Content\Redirects\Services\RedirectService;
use App\Filament\Resources\Redirects\RedirectResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateRedirect extends CreateRecord
{
    protected static string $resource = RedirectResource::class;

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
