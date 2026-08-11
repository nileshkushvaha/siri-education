<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Filament\Resources\Academic\CurriculumModuleResource;
use App\Models\CurriculumModule;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Module CRUD for a curriculum version — every mutation routes
 * through CurriculumService and is only possible while the owning
 * version is Draft (guarded both by ->visible() and by the service
 * itself, which is the actual authority).
 */
class CurriculumModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Modules';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(150),
            Textarea::make('description')
                ->rows(3)
                ->maxLength(2000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn ($query) => $query->withCount('topicAssignments'))
            ->columns([
                TextColumn::make('sort_order')->label('Order')->sortable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('topic_assignments_count')->label('Topics')->counts('topicAssignments'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isDraft() && (auth()->user()?->can('create', CurriculumModule::class) ?? false))
                    ->using(function (array $data): CurriculumModule {
                        try {
                            return app(CurriculumService::class)->addModule(auth()->user(), $this->getOwnerRecord(), $data);
                        } catch (CurriculumException $e) {
                            Notification::make()->title('Module not added')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->isDraft())
                    ->using(function (CurriculumModule $record, array $data): CurriculumModule {
                        try {
                            return app(CurriculumService::class)->updateModule(auth()->user(), $record, $data);
                        } catch (CurriculumException $e) {
                            Notification::make()->title('Module not updated')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }
                    }),
                Action::make('manageTopics')
                    ->label('Manage Topics')
                    ->icon('heroicon-m-list-bullet')
                    ->url(fn (CurriculumModule $record): string => CurriculumModuleResource::getUrl('edit', ['record' => $record])),
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->isDraft() && (auth()->user()?->can('delete', CurriculumModule::class) ?? false))
                    ->action(function (CurriculumModule $record): void {
                        try {
                            app(CurriculumService::class)->removeModule(auth()->user(), $record);
                            Notification::make()->title('Module removed')->success()->send();
                        } catch (CurriculumException $e) {
                            Notification::make()->title('Module not removed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('sort_order');
    }

    private function isDraft(): bool
    {
        /** @var CurriculumVersion $version */
        $version = $this->getOwnerRecord();

        return $version->status->isEditable();
    }
}
