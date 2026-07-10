<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Enums\InstructorStatus;
use App\Models\SubjectTopic;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin-managed topic coverage. Approval is a toggle here (mapped to
 * approved_at/approved_by via the page hooks) — coverage counts for
 * booking/marketplace only when active AND approved. This form never
 * shows pricing: student prices remain a separate, admin-only module.
 */
class InstructorSubjectTopicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Coverage')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('teacher_id')
                                ->label('Instructor')
                                ->relationship(
                                    'teacher',
                                    'name',
                                    fn (Builder $query) => $query
                                        ->whereHas('roles', fn (Builder $q) => $q->where('name', 'instructor'))
                                        ->whereHas('profile', fn (Builder $q) => $q->whereIn('instructor_status', InstructorStatus::bookableValues())),
                                )
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('subject_topic_id')
                                ->label('Topic')
                                ->options(fn (): array => SubjectTopic::query()
                                    ->active()
                                    ->with('subject:id,name')
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (SubjectTopic $topic): array => [
                                        $topic->id => sprintf('%s — %s', $topic->subject?->name, $topic->name),
                                    ])
                                    ->toArray())
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, $set): void {
                                    $set('subject_id', SubjectTopic::find($state)?->subject_id);
                                }),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('academic_level_id')
                                ->label('Academic Level')
                                ->relationship('academicLevel', 'name')
                                ->searchable()
                                ->preload()
                                ->placeholder('All levels')
                                ->helperText('Leave empty to cover this topic at every level.'),
                            TextInput::make('proficiency_level')
                                ->maxLength(50)
                                ->placeholder('e.g. expert'),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('is_primary')->label('Primary Topic'),
                            Toggle::make('is_active')->label('Active')->default(true),
                            Toggle::make('approved')
                                ->label('Approved')
                                ->helperText('Only active + approved coverage is bookable and marketplace-visible.')
                                ->dehydrated(false)
                                ->afterStateHydrated(fn (Toggle $component, $record) => $component->state($record?->approved_at !== null)),
                        ]),
                        // Kept in sync from the topic selection — a coverage
                        // row always references its topic's own subject.
                        Select::make('subject_id')
                            ->relationship('subject', 'name')
                            ->hidden()
                            ->dehydrated(),
                    ]),
            ]);
    }
}
