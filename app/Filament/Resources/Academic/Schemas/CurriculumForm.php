<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Schemas;

use App\Models\AcademicLevel;
use App\Models\Subject;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CurriculumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Curriculum Identity')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('subject_id')
                            ->label('Subject')
                            ->options(fn (): array => Subject::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->placeholder('Select a subject')
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Cannot be changed after creation — every version and topic assignment depends on this.'),
                        Select::make('academic_level_id')
                            ->label('Academic Level')
                            ->options(fn (): array => AcademicLevel::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->placeholder('Select an academic level')
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Cannot be changed after creation.'),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('e.g. Algebra Foundations')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, string $operation): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(150)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->placeholder('e.g. algebra-foundations')
                            ->helperText('Unique within the selected subject + academic level.'),
                    ]),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->placeholder('Brief overview of this curriculum…'),
                ]),
        ]);
    }
}
