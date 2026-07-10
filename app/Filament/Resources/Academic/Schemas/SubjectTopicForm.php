<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Enums\AcademicStatus;
use App\Models\SubjectTopic;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SubjectTopicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Topic Information')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('subject_id')
                                ->label('Subject')
                                ->relationship('subject', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->placeholder('Select a subject'),
                            Select::make('parent_id')
                                ->label('Parent Topic')
                                ->options(fn (Get $get, ?SubjectTopic $record): array => SubjectTopic::query()
                                    ->where('subject_id', $get('subject_id'))
                                    ->whereNull('parent_id')
                                    ->when($record, fn (Builder $q) => $q->whereKeyNot($record->id))
                                    ->orderBy('display_order')
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->searchable()
                                ->placeholder('None (top-level topic)')
                                ->helperText('One level of nesting only — a subtopic cannot have children.'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(150)
                                ->placeholder('e.g. Algebra')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, $set): void {
                                    if (filled($state)) {
                                        $set('slug', Str::slug($state));
                                    }
                                }),
                            TextInput::make('slug')
                                ->required()
                                ->maxLength(150)
                                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                ->placeholder('e.g. algebra')
                                ->helperText('Unique within the subject.'),
                        ]),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('Brief overview of this topic…')
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            Select::make('status')
                                ->options(
                                    collect(AcademicStatus::cases())
                                        ->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])
                                        ->toArray()
                                )
                                ->default(AcademicStatus::Active->value)
                                ->required(),
                            TextInput::make('display_order')
                                ->numeric()
                                ->default(0)
                                ->minValue(0),
                        ]),
                    ]),
            ]);
    }
}
