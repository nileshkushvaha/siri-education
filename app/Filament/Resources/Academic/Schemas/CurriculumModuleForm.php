<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Schemas;

use App\Models\CurriculumModule;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurriculumModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Module')
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('curriculum_version')
                        ->label('Curriculum Version')
                        ->content(fn (?CurriculumModule $record): string => $record !== null
                            ? sprintf('%s — v%d (%s)', $record->version->curriculum->name, $record->version->version_number, $record->version->status->label())
                            : '—'),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(150)
                        ->disabled(fn (?CurriculumModule $record): bool => $record !== null && ! $record->version->status->isEditable())
                        ->dehydrated(fn (?CurriculumModule $record): bool => $record !== null && $record->version->status->isEditable()),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(2000)
                        ->disabled(fn (?CurriculumModule $record): bool => $record !== null && ! $record->version->status->isEditable())
                        ->dehydrated(fn (?CurriculumModule $record): bool => $record !== null && $record->version->status->isEditable()),
                ]),
        ]);
    }
}
