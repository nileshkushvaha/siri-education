<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Pages;

use App\Content\Redirects\Exceptions\RedirectException;
use App\Content\Redirects\Services\RedirectService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\Redirect;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditRedirect extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Redirects'),
        ]);
    }

    /** Updates flow through the service — normalized, validated, audited. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Redirect $record */
        try {
            return app(RedirectService::class)->update(auth()->user(), $record, [
                'source_path' => $data['source_path'],
                'target_path' => $data['target_path'],
                'type' => $data['type'],
                'description' => $data['description'] ?? null,
            ]);
        } catch (RedirectException $e) {
            Notification::make()->title('Redirect not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
