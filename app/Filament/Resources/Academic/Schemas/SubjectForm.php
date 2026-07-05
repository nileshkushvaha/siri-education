<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\AcademicStatus;
use App\Models\Subject;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subject Information')
                    ->schema([
                        Select::make('academic_category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, Subject::class));
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique('subjects', 'slug', ignoreRecord: true)
                            ->maxLength(150)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000),
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
                        Select::make('countries')
                            ->label('Available In Countries')
                            ->relationship('countries', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Leave empty to make this subject available in every country.'),
                    ]),
            ]);
    }
}
