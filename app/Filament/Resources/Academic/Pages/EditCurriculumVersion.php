<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditCurriculumVersion extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Curriculum Versions'),
            $this->publishAction(),
            $this->archiveAction(),
            $this->retireAction(),
        ]);
    }

    /** @param  CurriculumVersion  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(CurriculumService::class)->updateDraft(auth()->user(), $record, $data);
        } catch (CurriculumException $e) {
            Notification::make()->title('Version not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Publishing locks this version\'s modules and topics against further structural changes. A future revision requires creating a new version.')
            ->visible(fn (): bool => $this->canManage() && $this->record->status === CurriculumVersionStatus::Draft)
            ->action(function (): void {
                try {
                    app(CurriculumService::class)->publish(auth()->user(), $this->record);
                    $this->record->refresh();
                    Notification::make()->title('Curriculum version published')->success()->send();
                } catch (CurriculumException $e) {
                    Notification::make()->title('Version not published')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('heroicon-m-archive-box')
            ->color('warning')
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(1000),
            ])
            ->visible(fn (): bool => $this->canManage() && $this->record->status === CurriculumVersionStatus::Published)
            ->action(function (array $data): void {
                try {
                    app(CurriculumService::class)->archive(auth()->user(), $this->record, (string) ($data['reason'] ?? ''));
                    $this->record->refresh();
                    Notification::make()->title('Curriculum version archived')->success()->send();
                } catch (CurriculumException $e) {
                    Notification::make()->title('Version not archived')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function retireAction(): Action
    {
        return Action::make('retire')
            ->label('Retire')
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')->label('Reason')->required()->maxLength(1000),
            ])
            ->visible(fn (): bool => $this->canManage() && $this->record->status === CurriculumVersionStatus::Archived)
            ->action(function (array $data): void {
                try {
                    app(CurriculumService::class)->retire(auth()->user(), $this->record, (string) ($data['reason'] ?? ''));
                    $this->record->refresh();
                    Notification::make()->title('Curriculum version retired')->success()->send();
                } catch (CurriculumException $e) {
                    Notification::make()->title('Version not retired')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('update', $this->record) ?? false;
    }
}
