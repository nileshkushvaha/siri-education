<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\CurriculumService;
use App\Models\CurriculumModule;
use App\Models\CurriculumModuleTopic;
use App\Models\SubjectTopic;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Assigns existing SubjectTopic rows into a CurriculumModule — never
 * creates topics itself (see docs/architecture/
 * phase-12.5-academic-taxonomy-subject-topics.md). The Select is
 * scoped to the curriculum's own Subject and active topics only, but
 * CurriculumService::assignTopic() is the actual authority — this is
 * a UI-layer convenience, not a substitute for it.
 */
class CurriculumModuleTopicsRelationManager extends RelationManager
{
    protected static string $relationship = 'topicAssignments';

    protected static ?string $title = 'Topics';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('subject_topic_id')
                ->label('Subject Topic')
                ->options(function (): array {
                    /** @var CurriculumModule $module */
                    $module = $this->getOwnerRecord();
                    $subjectId = $module->version->curriculum->subject_id;
                    $assignedIds = $module->topicAssignments()->pluck('subject_topic_id');

                    return SubjectTopic::query()
                        ->where('subject_id', $subjectId)
                        ->active()
                        ->whereNotIn('id', $assignedIds)
                        ->orderBy('display_order')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject_topic_id')
            ->modifyQueryUsing(fn ($query) => $query->with('topic'))
            ->columns([
                TextColumn::make('sort_order')->label('Order')->sortable(),
                TextColumn::make('topic.name')->label('Topic'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->isDraft() && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->using(function (array $data): CurriculumModuleTopic {
                        /** @var CurriculumModule $module */
                        $module = $this->getOwnerRecord();
                        $topic = SubjectTopic::query()->findOrFail($data['subject_topic_id']);

                        try {
                            return app(CurriculumService::class)->assignTopic(auth()->user(), $module, $topic);
                        } catch (CurriculumException $e) {
                            Notification::make()->title('Topic not assigned')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->isDraft() && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->action(function (CurriculumModuleTopic $record): void {
                        try {
                            app(CurriculumService::class)->unassignTopic(auth()->user(), $record);
                            Notification::make()->title('Topic removed')->success()->send();
                        } catch (CurriculumException $e) {
                            Notification::make()->title('Topic not removed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('sort_order');
    }

    private function isDraft(): bool
    {
        /** @var CurriculumModule $module */
        $module = $this->getOwnerRecord();

        return $module->version->status->isEditable();
    }
}
